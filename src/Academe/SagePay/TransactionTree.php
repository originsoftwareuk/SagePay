<?php

namespace Academe\SagePay;

use RuntimeException;
use Academe\SagePay\Exception;

class TransactionTree
{

    protected $pdo_connect = '';
    protected $pdo_username = '';
    protected $pdo_password = '';
    public $pdo_error_message = '';
    protected $transaction_table_name = 'sagepay_transactions';
    protected $pdo;
    protected $paymentId;
    protected $rootVendorTxCode;
    protected $hasTree = false;
    protected $tree = array();

    /**
     * Set the database credentials.
     * The $connect string is a PDO resource, e.g.
     *  'mysql:host=localhost;dbname=my_database'
     */
    public function setDatabase($connect, $username, $password, $tablename = null)
    {
        $this->pdo_connect = $connect;
        $this->pdo_username = $username;
        $this->pdo_password = $password;

        if (isset($tablename))
            $this->setTablename($tablename);

        return $this;
    }

    /**
     * Set the database table name.
     */
    public function setTablename($tablename)
    {
        $this->transaction_table_name = $tablename;
    }

    /**
     * Get the database table name.
     */
    public function getTablename($tablename)
    {
        return $this->transaction_table_name;
    }

    /**
     * Get a database connection.
     * Not handled as a singleton, even though that would most likely be how it is used.
     * Just put your own singleton wrapper around this if you prefer that.
     * TODO: what the hell, let's make this a singleton. We need a connection to read
     * the transaction, then save it again, so there are two for each use at least.
     */
    protected function getConnection()
    {
        if (!isset($this->pdo))
        {
            // Connect to the database.
            $pdo = new \PDO($this->pdo_connect, $this->pdo_username, $this->pdo_password);

            // Capture all errors.
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    public function setPaymentId($paymentId)
    {
        $this->paymentId = $paymentId;
        $this->buildTree();
    }

    public function buildTree()
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare('SELECT * FROM sagepay_transactions WHERE CustomData = :paymentId');
        $stmt->bindParam('paymentId', $this->paymentId, \PDO::PARAM_STR);
        $stmt->execute();
        $transaction = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($transaction) && isset($transaction['VendorTxCode']))
        {
            $rootVendorTxCode = $transaction['VendorTxCode'];
            $this->rootVendorTxCode = $rootVendorTxCode;
            $this->tree[$rootVendorTxCode] = array('data' => $transaction, 'authorisations' => array(), 'amounts' => array('authorisations' => 0, 'refunds' => 0, 'total' => 0, 'original' => $transaction['Amount'], 'max' => number_format($transaction['Amount'] * 1.15, 2)));
            $stmt = $pdo->prepare('SELECT * FROM sagepay_transactions spt WHERE spt.RelatedVendorTxCode = :VendorTxCode AND spt.TxType = "AUTHORISE" AND spt.Status = "OK"');
            $stmt->bindParam('VendorTxCode', $transaction['VendorTxCode'], \PDO::PARAM_STR);
            $stmt->execute();
            $authorisations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($authorisations as $authorisation)
            {
                $this->tree[$authorisation['RelatedVendorTxCode']]['authorisations'][$authorisation['VendorTxCode']] = array('authorisation' => $authorisation, 'refunds' => array(), 'total' => 0);
                $this->tree[$authorisation['RelatedVendorTxCode']]['authorisations'][$authorisation['VendorTxCode']]['total'] += $authorisation['Amount'];
                $this->tree[$authorisation['RelatedVendorTxCode']]['amounts']['authorisations'] += $authorisation['Amount'];
                $this->tree[$authorisation['RelatedVendorTxCode']]['amounts']['total'] += $authorisation['Amount'];

                $stmt = $pdo->prepare('SELECT * FROM sagepay_transactions spt WHERE spt.RelatedVendorTxCode = :VendorTxCode AND spt.TxType = "REFUND" AND spt.Status = "OK"');
                $stmt->bindParam('VendorTxCode', $authorisation['VendorTxCode'], \PDO::PARAM_STR);
                $stmt->execute();
                $refunds = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($refunds as $refund)
                {
                    $this->tree[$authorisation['RelatedVendorTxCode']]['authorisations'][$refund['RelatedVendorTxCode']]['refunds'][$refund['VendorTxCode']] = $refund;
                    $this->tree[$authorisation['RelatedVendorTxCode']]['authorisations'][$authorisation['VendorTxCode']]['total'] -= $refund['Amount'];
                    $this->tree[$authorisation['RelatedVendorTxCode']]['amounts']['refunds'] += $refund['Amount'];
                    $this->tree[$authorisation['RelatedVendorTxCode']]['amounts']['total'] -= $refund['Amount'];
                }
            }
        }
        $this->hasTree = true;
    }

    /**
     * Work out which authorisations to refund against.
     * 
     * Returns an array that can be fed to a refund method to perform the actual refunds.
     * If the refund amount is greater than the available amount to refund then the overage is returned instead
     * unless $force is true.
     * 
     * @param float $value
     * @param bool $force
     * @return array|float
     */
    public function getRefunds($value, $force = false)
    {
        if(!$this->hasTree)
        {
            return null;
        }
        $actualRefunds = array();
        $valueLeft = $value;
        foreach ($this->tree[$this->rootVendorTxCode]['authorisations'] as $authorisation)
        {
            if ($authorisation['total'] > 0 && $valueLeft > 0)
            {
                $amount = 0;
                if ($authorisation['total'] < $valueLeft)
                {
                    $amount = $authorisation['total'];
                    $valueLeft -= $amount;
                }
                else
                {
                    $amount = $valueLeft;
                    $valueLeft = 0;
                }
                $actualRefunds[] = array('RelatedVendorTxCode' => $authorisation['authorisation']['VendorTxCode'],
                    'RelatedVPSTxId' => $authorisation['authorisation']['VPSTxId'],
                    'RelatedSecurityKey' => $authorisation['authorisation']['SecurityKey'],
                    'RelatedTxAuthNo' => $authorisation['authorisation']['TxAuthNo'],
                    'Amount' => $amount);
            }
        }
        if($valueLeft > 0 && !$force)
        {
            return $valueLeft;
        }
        return $actualRefunds;
    }

    public function getAuthorisationTotal()
    {
        if(!$this->hasTree)
        {
            return null;
        }
        return $this->tree[$this->rootVendorTxCode]['amounts']['authorisations'];
    }

    public function getRefundTotal()
    {
        if(!$this->hasTree)
        {
            return null;
        }
        return $this->tree[$this->rootVendorTxCode]['amounts']['refunds'];
    }

    public function getTotal()
    {
        if(!$this->hasTree)
        {
            return null;
        }
        return $this->tree[$this->rootVendorTxCode]['amounts']['total'];
    }
}
