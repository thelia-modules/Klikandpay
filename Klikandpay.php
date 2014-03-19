<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia                                                                       */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*      along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Klikandpay;

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Install\Database;
use Thelia\Model\ConfigQuery;
use Thelia\Module\BaseModule;
use Thelia\Module\PaymentModuleInterface;
use Thelia\Tools\Redirect;
use Thelia\Tools\URL;


/**
 * Class Klikandpay
 *
 * @package Klikandpay
 * @author Thelia <info@thelia.net>
 */
class Klikandpay extends BaseModule implements PaymentModuleInterface
{

    const MODE_TEST = 0;
    const MODE_PROD = 1;
    const AMOUNT_PATTERN = '/^([\$]?)([0-9,\s]*\.?[0-9]{0,2})$/';
    const KEY = "klikandpay_key";


    /**
     * Method used just after the module activation and give the
     * possibility to create the tables used by the module.
     *
     * @param ConnectionInterface $con
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        // Tables creation for the module
        $database = new Database($con->getWrappedConnection());
        $database->insertSql(null, array(__DIR__ . '/Config/klikandpay.sql'));

        // Key used to secure the transaction
        if(null === ConfigQuery::read(self::KEY))
        {
            ConfigQuery::write(self::KEY, $this->keygen(40), false);
        }
    }


    /**
     * This method is call on Payment loop.
     * Check if the conditions are respected to display this payment method.
     *
     * @return boolean Return True (Display the payment method) or False (Payment method will not be display)
     */
    public function isValidPayment()
    {
        // Security: missing account id
        if(0 === intval(ConfigQuery::read('klikandpay_identifiant')))
            return false;

        // Retrieve the total amount in the cart without shipping
        $total = $this->getCartTotal();

        // Minimum amount
        if(intval(ConfigQuery::read('klikandpay_montant_min')) > 0 && $total < ConfigQuery::read('klikandpay_montant_min'))
            return false;

        // Maximum amount
        if(intval(ConfigQuery::read('klikandpay_montant_max')) > 0 && $total > ConfigQuery::read('klikandpay_montant_max'))
            return false;

        // Return True if all conditions are respected
        return true;
    }


    /**
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is send to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway. On your response you can return this form already
     *  completed, ready to be sent
     *
     * @param  \Thelia\Model\Order $order processed order
     * @return null|\Thelia\Core\HttpFoundation\Response
     */
    public function pay(Order $order)
    {
        // Array of data to send
        $parameters = $this->getParameters($order->getTotalAmount(), $order->getCustomer());

        // Hash to secure the transaction
        $hash = $this->getHash($parameters, $order->getRef());
        $order->setTransactionRef($hash);
        $order->save();

        // Redirect to a new page to avoid multiple orders if the user refresh the page
        $this->redirect(URL::getInstance()->absoluteUrl("/klikandpay/order/pay/$hash"));
    }


    /**
     * Method used to return the url based on the operating mode
     *
     * @return string Return the URL for Klik&Pay's server based on the operating mode
     */
    function getAction()
    {
        return (ConfigQuery::read('klikandpay_mode') == self::MODE_PROD) ? ConfigQuery::read('klikandpay_url_prod') : ConfigQuery::read('klikandpay_url_test');
    }


    /**
     * Method used to build an array of datas to secure the transaction
     *
     * @param int $amount Cart's Total amount
     * @param \Thelia\Model\Customer $customer Customer
     *
     * @return array Return an array
     */
    function getParameters($amount, Customer $customer = null)
    {
        // Customer default address
        $address = $customer->getDefaultAddress();

        return array(
            'ID'                => ConfigQuery::read('klikandpay_identifiant'),
            'NOM'               => mb_strtoupper($customer->getLastname(), 'UTF-8'),
            'PRENOM'            => ucfirst(strtolower($customer->getFirstname())),
            'ADRESSE'           => $address->getAddress1(),
            'CODEPOSTAL'        => $address->getZipcode(),
            'VILLE'             => $address->getCity(),
            'PAYS'              => $address->getCountry()->getIsoalpha2(),
            'EMAIL'             => $customer->getEmail(),
            'TEL'               => ($address->getPhone() !== "") ? $address->getPhone() : $address->getCellphone(),
            'MONTANT'           => $amount
        );
    }


    /**
     * Method used to secure the transaction
     *
     * @param array $params Array used to generate the hash
     * @param string $ref Order ref number
     *
     * @return string Returns a string
     */
    function getHash($params, $ref)
    {
        // The command reference number is added to ensure we have an unique hash
        $params['ref'] = $ref;

        // Concatenation : ID|NOM|PRENOM|ADRESSE|CODEPOSTAL|VILLE|PAYS|EMAIL|TEL|MONTANT|REF
        $concatenation = implode($params, '|');

        // Key
        $binKey = pack('H*', bin2hex(ConfigQuery::read(self::KEY)));

        return strtoupper(hash_hmac('sha1', $concatenation, $binKey));
    }


    /**
     * Method used to get the total amount of the shopping cart
     *
     * @return float Return the total of the cart (Included taxes) without shipping
     */
    public function getCartTotal()
    {
        $taxCountry = $this->getContainer()->get('thelia.taxEngine')->getDeliveryCountry();

        return  $this->getRequest()->getSession()->getCart()->getTaxedAmount($taxCountry, true);
    }


    /**
     * Method used to generate a random key
     *
     * @param int $length Length of the string
     * @param string $list Allowed characters
     *
     * @return string Returns a string
     */
    public function keygen($length = 40, $list = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')
    {
        $key = '';
        $nb = strlen($list);
        for ($i = 0; $i < $length; $i++, $key .= $list[mt_rand(1, $nb)-1]);

        return $key;
    }


    /**
     * @return \Thelia\Core\HttpFoundation\Request
     */
    public function getRequest()
    {
        return $this->container->get('request');
    }

    /**
     * Redirect request to the specified url
     *
     * @param string $url
     * @param int    $status  http status. Must be a 30x status
     * @param array  $cookies
     */
    public function redirect($url, $status = 302, $cookies = array())
    {
        Redirect::exec($url, $status, $cookies);
    }


}
