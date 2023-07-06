<?php

namespace OfxParser;

use SimpleXMLElement;
use OfxParser\Utils;
use OfxParser\Entities\AccountInfo;
use OfxParser\Entities\BankAccount;
use OfxParser\Entities\Institute;
use OfxParser\Entities\SignOn;
use OfxParser\Entities\Statement;
use OfxParser\Entities\Status;
use OfxParser\Entities\Transaction;

/**
 * The OFX object
 *
 * Heavily refactored from Guillaume Bailleul's grimfor/ofxparser
 *
 * Second refactor by Oliver Lowe to unify the API across all
 * OFX data-types.
 *
 * Based on Andrew A Smith's Ruby ofx-parser
 *
 * @author Guillaume BAILLEUL <contact@guillaume-bailleul.fr>
 * @author James Titcumb <hello@jamestitcumb.com>
 * @author Oliver Lowe <mrtriangle@gmail.com>
 */
class Ofx
{
    /**
     * @var Header[]
     */
    public $header = [];

    /**
     * @var SignOn
     */
    public $signOn;

    /**
     * @var AccountInfo[]
     */
    public $signupAccountInfo;

    /**
     * @var BankAccount[]
     */
    public $bankAccounts = [];

    /**
     * Only populated if there is only one bank account
     * @var BankAccount|null
     * @deprecated This will be removed in future versions
     */
    public $bankAccount;

    /**
     * @param SimpleXMLElement $xml
     * @throws \Exception
     */
    public function __construct(SimpleXMLElement $xml)
    {

        if (!property_exists($xml, 'SIGNONMSGSRSV1') || !property_exists($xml->SIGNONMSGSRSV1, 'SONRS')) {
            $xml = self::createTags($xml);
        }

        $this->signOn = $this->buildSignOn($xml->SIGNONMSGSRSV1->SONRS);
        $this->signupAccountInfo = $this->buildAccountInfo($xml->SIGNUPMSGSRSV1->ACCTINFOTRNRS);

        if (isset($xml->BANKMSGSRSV1)) {
            $this->bankAccounts = $this->buildBankAccounts($xml);
        } elseif (isset($xml->CREDITCARDMSGSRSV1)) {
            $this->bankAccounts = $this->buildCreditAccounts($xml);
        }

        // Set a helper if only one bank account
        if (count($this->bankAccounts) === 1) {
            $this->bankAccount = $this->bankAccounts[0];
        }
    }

        /**
     * @return Ofx New tag creation enchancement
     */
    public function createTags( $xml) {

            if(!property_exists($xml, 'SIGNONMSGSRSV1')) {

                $newXml = new SimpleXMLElement('<root/>');
        
                $signonmsgsrsv1 = $newXml->addChild('SIGNONMSGSRSV1');
                $signonmsgsrsv1->addChild('SONRS');
            
                foreach ($xml->children() as $child) {
                    $newChild = $newXml->addChild($child->getName(), (string) $child);
                    foreach ($child->attributes() as $attrKey => $attrValue) {
                        $newChild->addAttribute($attrKey, $attrValue);
                    }
                    self::copyChildren($child, $newChild);
                }
            
                $xml = $newXml;

            }else if(!property_exists($xml->SIGNONMSGSRSV1, 'SONRS')) {

                $newXml = new SimpleXMLElement('<root/>');
    
                foreach ($xml->children() as $child) {
                    $newChild = $newXml->addChild($child->getName(), (string) $child);
                    foreach ($child->attributes() as $attrKey => $attrValue) {
                        $newChild->addAttribute($attrKey, $attrValue);
                    }
                    if ($child->getName() === 'SIGNONMSGSRSV1') {
                        $sonrs = $newChild->addChild('SONRS');
                    }
            
                    self::copyChildren($child, $newChild);
                }

                $xml = $newXml;
            }

        return $xml;
    }

       /**
     * Copy and return the new tag
     */
   public function copyChildren($from, $to) {
    foreach ($from->children() as $child) {
        $newChild = $to->addChild($child->getName(), (string) $child);
        foreach ($child->attributes() as $attrKey => $attrValue) {
            $newChild->addAttribute($attrKey, $attrValue);
        }
        self::copyChildren($child, $newChild);
    }
}

    /**
     * Get the transactions that have been processed
     *
     * @return array
     * @deprecated This will be removed in future versions
     */
    public function getTransactions()
    {
        return $this->bankAccount->statement->transactions;
    }

    /**
     * @param array $header
     * @return Ofx
     */
    public function buildHeader(array $header)
    {
        $this->header = $header;

        return $this;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return SignOn
     * @throws \Exception
     */
    protected function buildSignOn(SimpleXMLElement $xml)
    {
        $signOn = new SignOn();
        $signOn->status = $this->buildStatus($xml->STATUS);
        $signOn->date = Utils::createDateTimeFromStr($xml->DTSERVER, true);
        $signOn->language = $xml->LANGUAGE;

        $signOn->institute = new Institute();
        $signOn->institute->name = $xml->FI->ORG;
        $signOn->institute->id = $xml->FI->FID;

        return $signOn;
    }

    /**
     * @param SimpleXMLElement|null $xml
     * @return array AccountInfo
     */
    private function buildAccountInfo(SimpleXMLElement $xml = null)
    {
        if (null === $xml || !isset($xml->ACCTINFO)) {
            return [];
        }

        $accounts = [];
        foreach ($xml->ACCTINFO as $account) {
            $accountInfo = new AccountInfo();
            $accountInfo->desc = $account->DESC;
            $accountInfo->number = $account->ACCTID;
            $accounts[] = $accountInfo;
        }

        return $accounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildCreditAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];

        foreach ($xml->CREDITCARDMSGSRSV1->CCSTMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildCreditAccount($accountStatement);
        }
        return $bankAccounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildBankAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];
        foreach ($xml->BANKMSGSRSV1->STMTTRNRS as $accountStatement) {
            foreach ($accountStatement->STMTRS as $statementResponse) {
                $bankAccounts[] = $this->buildBankAccount($accountStatement->TRNUID, $statementResponse);
            }
        }
        return $bankAccounts;
    }

    /**
     * @param string $transactionUid
     * @param SimpleXMLElement $statementResponse
     * @return BankAccount
     * @throws \Exception
     */
    private function buildBankAccount($transactionUid, SimpleXMLElement $statementResponse)
    {
        $bankAccount = new BankAccount();
        $bankAccount->transactionUid = $transactionUid;
        $bankAccount->agencyNumber = $statementResponse->BANKACCTFROM->BRANCHID;
        $bankAccount->accountNumber = $statementResponse->BANKACCTFROM->ACCTID;
        $bankAccount->routingNumber = $statementResponse->BANKACCTFROM->BANKID;
        $bankAccount->accountType = $statementResponse->BANKACCTFROM->ACCTTYPE;
       $bankAccount->balance = isset($statementResponse->LEDGERBAL->BALAMT) ? (string)$statementResponse->LEDGERBAL->BALAMT : '';
        $bankAccount->balanceDate = isset($statementResponse->LEDGERBAL->DTASOF) ? $this->createDateTimeFromStr($statementResponse->LEDGERBAL->DTASOF, true) : null;

        $bankAccount->statement = new Statement();
        $bankAccount->statement->currency = $statementResponse->CURDEF;

        $bankAccount->statement->startDate = Utils::createDateTimeFromStr(
            $statementResponse->BANKTRANLIST->DTSTART
        );

        $bankAccount->statement->endDate = Utils::createDateTimeFromStr(
            $statementResponse->BANKTRANLIST->DTEND
        );

        $bankAccount->statement->transactions = $this->buildTransactions(
            $statementResponse->BANKTRANLIST->STMTTRN
        );

        return $bankAccount;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildCreditAccount(SimpleXMLElement $xml)
    {
        $nodeName = 'CCACCTFROM';
        if (!isset($xml->CCSTMTRS->$nodeName)) {
            $nodeName = 'BANKACCTFROM';
        }

        $creditAccount = new BankAccount();
        $creditAccount->transactionUid = $xml->TRNUID;
        $creditAccount->agencyNumber = $xml->CCSTMTRS->$nodeName->BRANCHID;
        $creditAccount->accountNumber = $xml->CCSTMTRS->$nodeName->ACCTID;
        $creditAccount->routingNumber = $xml->CCSTMTRS->$nodeName->BANKID;
        $creditAccount->accountType = $xml->CCSTMTRS->$nodeName->ACCTTYPE;
        $creditAccount->balance = isset($xml->CCSTMTRS->LEDGERBAL->BALAMT) ? (string)$xml->CCSTMTRS->LEDGERBAL->BALAMT : '';
        $creditAccount->balanceDate = isset($xml->CCSTMTRS->LEDGERBAL->DTASOF) ? $this->createDateTimeFromStr($xml->CCSTMTRS->LEDGERBAL->DTASOF, true) : null;

        $creditAccount->statement = new Statement();
        $creditAccount->statement->currency = $xml->CCSTMTRS->CURDEF;
        $creditAccount->statement->startDate = Utils::createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTSTART);
        $creditAccount->statement->endDate = Utils::createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTEND);
        $creditAccount->statement->transactions = $this->buildTransactions($xml->CCSTMTRS->BANKTRANLIST->STMTTRN);

        return $creditAccount;
    }

    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildTransactions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new Transaction();
            $transaction->type = (string)$t->TRNTYPE;
            $transaction->date = Utils::createDateTimeFromStr($t->DTPOSTED);
            if ('' !== (string)$t->DTUSER) {
                $transaction->userInitiatedDate = Utils::createDateTimeFromStr($t->DTUSER);
            }
            $transaction->amount = Utils::createAmountFromStr($t->TRNAMT);
            $transaction->uniqueId = (string)$t->FITID;
            $transaction->name = (string)$t->NAME;
            $transaction->memo = (string)$t->MEMO;
            $transaction->sic = $t->SIC;
            $transaction->checkNumber = $t->CHECKNUM;
            $return[] = $transaction;
        }

        return $return;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return Status
     */
    private function buildStatus(SimpleXMLElement $xml)
    {
        $status = new Status();
        $status->code = $xml->CODE;
        $status->severity = $xml->SEVERITY;
        $status->message = $xml->MESSAGE;

        return $status;
    }

    /**
     * Create a DateTime object from a valid OFX date format
     *
     * Supports:
     * YYYYMMDDHHMMSS.XXX[gmt offset:tz name]
     * YYYYMMDDHHMMSS.XXX
     * YYYYMMDDHHMMSS
     * YYYYMMDD
     *
     * @param  string $dateString
     * @param  boolean $ignoreErrors
     * @return \DateTime $dateString
     * @throws \Exception
     */
    private function createDateTimeFromStr($dateString, $ignoreErrors = false)
    {
        $regex = '/'
            . "(\d{4})(\d{2})(\d{2})?"     // YYYYMMDD             1,2,3
            . "(?:(\d{2})(\d{2})(\d{2}))?" // HHMMSS   - optional  4,5,6
            . "(?:\.(\d{3}))?"             // .XXX     - optional  7
            . "(?:\[-?\d+\:\w{3}\])?"      // [-n:TZ]  - optional  8,9
            . '/';

        if (preg_match($regex, $dateString, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = isset($matches[4]) ? $matches[4] : 0;
            $min = isset($matches[5]) ? $matches[5] : 0;
            $sec = isset($matches[6]) ? $matches[6] : 0;

            $format = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $min . ':' . $sec;

            try {
                return new \DateTime($format);
            } catch (\Exception $e) {
                if ($ignoreErrors) {
                    return null;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Failed to initialize DateTime for string: ' . $dateString);
    }

    /**
     * Create a formatted number in Float according to different locale options
     *
     * Supports:
     * 000,00 and -000,00
     * 0.000,00 and -0.000,00
     * 0,000.00 and -0,000.00
     * 000.00 and 000.00
     *
     * @param  string $amountString
     * @return float
     */
    private function createAmountFromStr($amountString)
    {
        $amountString = trim($amountString);

        if (preg_match('/^(?<signal>[-\+]?)(?<integer>.*)(?<separator>[\.,])(?<decimals>[\d]+)$/', $amountString, $matches) === 1) {
            $amountString = $matches['signal'] . preg_replace('/[^\d]+/', '', $matches['integer']) . '.' . $matches['decimals'];
        }

        return (float)$amountString;
    }
}
