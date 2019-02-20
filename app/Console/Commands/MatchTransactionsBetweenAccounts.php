<?php

/**
 * MatchTransactionsBetweenAccounts.php
 * Copyright (c) 2018 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Console\Commands;

use Carbon\Carbon;
use FireflyIII\Helpers\Collector\TransactionCollectorInterface;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\TransactionRules\Processor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *
 * Class MatchTransactionsBetweenAccounts
 *
 * @codeCoverageIgnore
 */
class MatchTransactionsBetweenAccounts extends Command
{
    use VerifiesAccessToken;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will match transactions between different banks and make it a transfer.';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature
        = 'firefly:match-transactions-between-accounts
                            {--user=1 : The user ID that the import should import for.}
                            {--token= : The user\'s access token.}
                            {--accounts= : A comma-separated list of asset accounts or liabilities to apply your rules to.}
                            {--name= : Name of opposing account.}
                            {--skipOthers= : skippy.}
                            {--start_date= : The date of the earliest transaction to be included (inclusive). If omitted, will be your very first transaction ever. Format: YYYY-MM-DD}
                            {--end_date= : The date of the latest transaction to be included (inclusive). If omitted, will be your latest transaction ever. Format: YYYY-MM-DD}';
    /** @var Collection */
    private $accounts;
    /** @var Carbon */
    private $endDate;
    /** @var Collection */
    private $results;
    /** @var Carbon */
    private $startDate;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->accounts = new Collection;
        $this->results = new Collection;
    }

    /**
     * Execute the console command.
     *
     * @return int
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    public function handle(): int
    {
        if (!$this->verifyAccessToken()) {
            $this->error('Invalid access token.');

            return 1;
        }

        $result = $this->verifyInput();
        if (false === $result) {
            return 1;
        }


        // get transactions from asset accounts.
        /** @var TransactionCollectorInterface $collector */
        $collector = app(TransactionCollectorInterface::class);
        $collector->setUser($this->getUser());
        $collector->setAccounts($this->accounts);
        $collector->setRange($this->startDate, $this->endDate);
        $collector->setTypes([TransactionType::DEPOSIT]);
        $collector->withOpposingAccount();
        $transactions = $collector->getTransactions();
        $bar = $this->output->createProgressBar($transactions->count());
        $bar->start();
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($this->getUser());
        $accounts = $repository->getAccountsByType([AccountType::ASSET]);

        foreach ($transactions as $transaction) {
            $bar->advance();
            /** @var TransactionCollectorInterface $withdrawCollector */
            $withdrawCollector = app(TransactionCollectorInterface::class);
            $withdrawCollector->setUser($this->getUser());
            $withdrawCollector->setAccounts($accounts->filter->whereNotInStrict('id', $transaction->account->id));
            $withdrawCollector->setRange(
                $transaction->date->copy()->modify('-7 days'),
                $transaction->date->copy()->modify('+7 days')
            );
            $withdrawCollector->setTypes([TransactionType::WITHDRAWAL]);
            $withdrawCollector->amountIs($transaction->transaction_amount);
            $foundTransactions = $withdrawCollector->getTransactions();
            if (0 === $foundTransactions->count()) {
                continue;
            }

            if ($transaction->opposing_account_name !== $this->option('name')) {
                if ($this->hasOption('skipOthers')) {
                    continue;
                }
                $answer = $this->output->ask(
                    \sprintf(
                        'Should we process this transaction: %s with description "%s" and name "%s"?',
                        $transaction->journal_id,
                        $transaction->description,
                        $transaction->opposing_account_name
                    )
                );

                if ('y' !== $answer) {
                    continue;
                }
            }

            if ($foundTransactions->count() > 1) {
                $answer = $this->output->ask('Skip error?');

                if ('y' === $answer) {
                    continue;
                }

                $this->output->newLine();
                foreach ($foundTransactions as $trans) {
                    $this->output->writeln($trans->journal_id);
                }
                $answer = $this->output->ask(\sprintf('Pick a transaction for %s:', $transaction->journal_id));

                if (!$answer) {
                    throw new \Exception(
                        \sprintf('More then one transaction found for transactionId: %s', $transaction->journal_id)
                    );
                }

                $foundTransaction = $foundTransactions->whereStrict('journal_id', (int)$answer)->first();
            } else {
                /** @var Transaction $foundTransaction */
                $foundTransaction = $foundTransactions->first();
            }
            $this->results->push($transaction);
            $journal = $transaction->transactionJournal;
            $journal
                ->transactions()
                ->where('amount', '<', 0)
                ->update(['account_id' => $foundTransaction->account->id]);
            $newType = TransactionType::whereType(TransactionType::TRANSFER)->first();
            $journal->transaction_type_id = $newType->id;
            $journal->save();
            $foundTransaction->transactionJournal->delete();
            foreach ($foundTransaction->transactionJournal->transactions() as $trans) {
                $trans->delete();
            }
        }
        $bar->finish();

        $this->line('');
        if (0 === $this->results->count()) {
            $this->line('The rules were fired but did not influence any transactions.');
        }
        if ($this->results->count() > 0) {
            $this->line(
                sprintf('The rule(s) was/were fired, and influenced %d transaction(s).', $this->results->count())
            );
            foreach ($this->results as $result) {
                $this->line(
                    vsprintf(
                        'Transaction #%d: "%s" (%s %s)',
                        [
                            $result->journal_id,
                            $result->description,
                            $result->transaction_currency_code,
                            round($result->transaction_amount, $result->transaction_currency_dp),
                        ]
                    )
                );
            }
        }

        return 0;
    }

    /**
     *
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    private function parseDates(): void
    {
        // parse start date.
        $startDate = Carbon::create()->startOfMonth();
        $startString = $this->option('start_date');
        if (null === $startString) {
            /** @var JournalRepositoryInterface $repository */
            $repository = app(JournalRepositoryInterface::class);
            $repository->setUser($this->getUser());
            $first = $repository->firstNull();
            if (null !== $first) {
                $startDate = $first->date;
            }
        }
        if (null !== $startString && '' !== $startString) {
            $startDate = Carbon::createFromFormat('Y-m-d', $startString);
        }

        // parse end date
        $endDate = Carbon::now();
        $endString = $this->option('end_date');
        if (null !== $endString && '' !== $endString) {
            $endDate = Carbon::createFromFormat('Y-m-d', $endString);
        }

        if ($startDate > $endDate) {
            [$endDate, $startDate] = [$startDate, $endDate];
        }

        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    /**
     * @return bool
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    private function verifyInput(): bool
    {
        // verify account.
        $result = $this->verifyInputAccounts();
        if (false === $result) {
            return $result;
        }

        $this->parseDates();

        //$this->line('Number of rules found: ' . $this->rules->count());
        $this->line('Start date is '.$this->startDate->format('Y-m-d'));
        $this->line('End date is '.$this->endDate->format('Y-m-d'));

        return true;
    }

    /**
     * @return bool
     * @throws \FireflyIII\Exceptions\FireflyException
     */
    private function verifyInputAccounts(): bool
    {
        $accountString = $this->option('accounts');
        if (null === $accountString || '' === $accountString) {
            $this->error('Please use the --accounts to indicate the accounts to apply rules to.');

            return false;
        }
        $finalList = new Collection;
        $accountList = explode(',', $accountString);

        if (0 === \count($accountList)) {
            $this->error('Please use the --accounts to indicate the accounts to apply rules to.');

            return false;
        }

        /** @var AccountRepositoryInterface $accountRepository */
        $accountRepository = app(AccountRepositoryInterface::class);
        $accountRepository->setUser($this->getUser());

        foreach ($accountList as $accountId) {
            $accountId = (int) $accountId;
            $account = $accountRepository->findNull($accountId);
            if (null !== $account
                && \in_array(
                    $account->accountType->type,
                    [
                        AccountType::DEFAULT,
                        AccountType::DEBT,
                        AccountType::ASSET,
                        AccountType::LOAN,
                        AccountType::MORTGAGE,
                    ],
                    true
                )
            ) {
                $finalList->push($account);
            }
        }

        if (0 === $finalList->count()) {
            $this->error('Please make sure all accounts in --accounts are asset accounts or liabilities.');

            return false;
        }
        $this->accounts = $finalList;

        return true;

    }
}
