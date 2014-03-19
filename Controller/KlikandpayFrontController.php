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

namespace Klikandpay\Controller;

use Klikandpay\Event\KlikandpayReturnEvent;
use Klikandpay\Klikandpay;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;


/**
 * Class KlikandpayFrontController
 * @author Thelia <info@thelia.net>
 */
class KlikandpayFrontController extends BaseFrontController
{
    const ORDER_PAY = 'order-payment';
    const ORDER_PLACED = 'order-placed';
    const ORDER_FAILED = 'order-failed';

    /**
     * Method used to send the data to Klik & Pay website
     *
     * @param  string $hash hash to retrieve order
     *
     * @return \Thelia\Core\HttpFoundation\Response
     */
    public function payAction($hash)
    {
        try {
            $order = $this->findOrder($hash);

            // Security : check current User
            if($order->getCustomerId() !== $this->getSession()->getCustomerUser()->getId())
                throw new \Exception("Sorry, this order doesn't belong to you.");

            // Array of data to send
            $klikandpay = new Klikandpay();
            $parameters = $klikandpay->getParameters($order->getTotalAmount(), $order->getCustomer());

            // Send secured hash to Klik & Pay
            $parameters['RETOUR'] = $hash;

            // Return URL (RETOURVOK : Variable used to complete the URL if the transaction is accepted)
            if (ConfigQuery::read('klikandpay_retourvok') != "") {
                $parameters['RETOURVOK'] = $this->returnURL(ConfigQuery::read('klikandpay_retourvok'), $order);
            }

            // Return URL (RETOURVHS : Variable used to complete the URL if the transaction is refused or cancelled)
            if (ConfigQuery::read('klikandpay_retourvhs') != "") {
                $parameters['RETOURVHS'] = $this->returnURL(ConfigQuery::read('klikandpay_retourvhs'), $order);
            }

            // Multilingual website
            $parameters['L'] = $this->getSession()->getLang()->getCode();

            return $this->render(
                self::ORDER_PAY,
                array('fields' => $parameters, 'action' => $klikandpay->getAction())
            );

        } catch (\Exception $e) {
            return $this->render(self::ORDER_FAILED);
        }
    }


    /**
     * Method used when an payment is accepeted
     *
     * @param  string $hash hash to retrieve order
     *
     * @throws \Exception
     *
     * @return \Thelia\Core\HttpFoundation\Response
     */
    public function placedAction($hash)
    {
        $order = null;

        // Retrieve the order by id or ref or hash
        $allowed = array('%order_hash%', '%order_id%', '%order_ref%');
        foreach($allowed as $type)
        {
            if(strpos(ConfigQuery::read('klikandpay_retourvok'), $type) !== FALSE)
            {
                $order = $this->findOrder($hash, $type);
                break; // will leave the foreach loop and also "break" the if statement
            }
        }

        if($order === null) {
            throw new \Exception("We are unable to retrieve your order.");
        }

        // Empty cart
        $cart_event = new CartEvent($this->getSession()->getCart());
        $this->dispatch(TheliaEvents::CART_CLEAR, $cart_event);

        return $this->render(
            self::ORDER_PLACED,
            array('placed_order_id' => $order->getId())
        );
    }


    /**
     * Method used when an payment is refused or canceled
     *
     * @param  string $hash hash to retrieve order (Not used)
     *
     * @throws \Exception
     *
     * @return \Thelia\Core\HttpFoundation\Response
     */
    public function failedAction($hash)
    {
        $order = null;

         // Retrieve the order by id or ref or hash
        $allowed = array('%order_hash%', '%order_id%', '%order_ref%');
        foreach($allowed as $type)
        {
            if(strpos(ConfigQuery::read('klikandpay_retourvok'), $type) !== FALSE)
            {
                $order = $this->findOrder($hash, $type);
                break; // will leave the foreach loop and also "break" the if statement
            }
        }

        if($order === null)
            throw new \Exception("We are unable to retrieve your order.");

        return $this->render(
            self::ORDER_FAILED,
            array('failed_order_id' => $order->getId())
        );
    }


    /**
     * Method used to change the status of an order when the payment
     * is accepted and if the marchant has configured a dynamic url
     * in the Klik & Pay's backoffice
     *
     * @return none
     */
    public function confirmAction()
    {
        try {
            $commande = $this->getRequest()->get('commande');
            $numxkp = $this->getRequest()->get('NUMXKP');
            $response = $this->getRequest()->get('RESPONSE');
            $montantxkp = $this->getRequest()->get('MONTANTXKP');

            // Klik&Pay Transaction Number is mandatory and the response from Klik & Pay has to be equal to '00'
            if( empty($numxkp) || $response !== '00' )
                throw new \Exception('Error with return parameters from Klik & Pay.');

            // Retrieve the order
            $order = $this->findOrder($commande);

            // Hash (Total without shipping)
            $klikandpay = new Klikandpay();
            $parameters = $klikandpay->getParameters($montantxkp - $order->getPostage(), $order->getCustomer());
            $hash = $klikandpay->getHash($parameters, $order->getRef());

            // Verify if we can trust the transaction
            if($hash !== $commande)
                throw new \Exception("The secure hash does not match: " . $hash . " <> " . $commande);

            // Verify if we can trust the transaction
            if($order->getStatusId() !== OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_NOT_PAID)->getId())
                throw new \Exception("This order was already been paid: " . $order->getStatusId());

            // Set order status as PAID
            $event = new OrderEvent($order);
            $event->setStatus(OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID)->getId());
            //$this->dispatch(TheliaEvents::ORDER_UPDATE_STATUS,$event);

            // Save Transaction number from Klik & Pay
            $order->setTransactionRef($numxkp);
            //$order->save();

            // Save all the return values from Klikandpay
            $event = new KlikandpayReturnEvent(
                $this->getRequest()->get('DEVISEXKP'),
                $this->getRequest()->get('IPXKP'),
                $montantxkp,
                $numxkp,
                $order->getId(),
                $this->getRequest()->get('PAIEMENT'),
                $this->getRequest()->get('PAYSBXKP'),
                $this->getRequest()->get('PAYSRXKP'),
                $this->getRequest()->get('SCOREXKP'),
                $commande
            );
            $this->dispatch('action.createKlikandpayReturn', $event);

        } catch (\Exception $e) {
            // Configure LOG for Klikandpay
            $log = Tlog::getInstance();
            $log->setDestinations("\\Thelia\\Log\\Destination\\TlogDestinationFile");
            $log->setConfig("\\Thelia\\Log\\Destination\\TlogDestinationFile", 0, THELIA_ROOT."log".DS."log-klikandpay.txt");
            $log->error(sprintf('Klik&Pay confirmation page => URL: %s <> message: %s', $this->getRequest()->getUri() , $e->getMessage()));

            // Get log back to previous state
            $log->setDestinations("\\Thelia\\Log\\Destination\\TlogDestinationRotatingFile");
        }

        // We don't need to render the view
        die();
    }


    /**
     * Method used to replace some elements in the url
     *
     * @param  string $variable
     * @param  \Thelia\Model\Order $order processed order
     *
     * @return string Return's URL with the real values
     */
    function returnURL($variable, Order $order)
    {
        $variable = strtolower($variable);

        $variable = str_replace('%order_id%', $order->getId(), $variable);
        $variable = str_replace('%order_ref%', $order->getRef(), $variable);
        $variable = str_replace('%order_hash%', $order->getTransactionRef(), $variable);

        return $variable;
    }


    /**
     * Method used to retrieve an order (By default, we use the secure hash)
     *
     * @param $hash
     * @param $type
     *
     * @throws \Exception
     *
     * @return \Thelia\Model\Order $order  processed order
     */
    public function findOrder($hash, $type = '%order_hash%')
    {

        $order = null;

        switch (strtolower($type)) {
            case '%order_id%':
                $order = OrderQuery::create()->findPk($hash);
                break;
            case '%order_ref%':
                $order = OrderQuery::create()->filterByRef($hash)->findOne();
                break;
            case '%order_hash%':
                $order = OrderQuery::create()->filterByTransactionRef($hash)->findOne();
                break;
        }

        if($order === null) {
            throw new \Exception("We are unable to retrieve your order.");
        }

        return $order;
    }

}

