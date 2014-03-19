<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
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
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace ImportT1\Controller\Front;

use ImportT1\Model\CustomerTemp;
use ImportT1\Model\CustomerTempQuery;
use Thelia\Controller\Front\BaseFrontController;
use Front\Controller\CustomerController as BaseCustomerController;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Form\CustomerLogin;
use Thelia\Model\CustomerQuery;

/**
 * Class CustomerController
 * @package ImportT1\Controller\Front
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 */
class CustomerController extends BaseFrontController
{
    public function loginAction()
    {
        $customerController = new BaseCustomerController();
        $customerController->setContainer($this->container);
        $customerController->loginAction();

        if (! $this->getSecurityContext()->hasCustomerUser()) {

            $request = $this->getRequest();
            $customerLoginForm = new CustomerLogin($request);

            try {
                $form = $this->validateForm($customerLoginForm, "post");
                $request = CustomerTempQuery::create();

                $customerTemp = $request
                    ->where('`customer_temp`.email = ?', $form->get('email')->getData(), \PDO::PARAM_STR)
                    ->where('`customer_temp`.password = PASSWORD(?)', $form->get('password')->getData(), \PDO::PARAM_STR)
                    ->where('`customer_temp`.processed = 0')
                    ->findOne();

                if (null !== $customerTemp) {
                    $customer = CustomerQuery::create()
                        ->findOneByEmail($form->get('email')->getData());

                    $customer->setPassword($form->get('password')->getData())
                        ->save();

                    $customerTemp
                        ->setProcessed(true)
                        ->save();
                    ;

                    $this->dispatch(TheliaEvents::CUSTOMER_LOGIN, new CustomerLoginEvent($customer));
                    $this->redirectSuccess($customerLoginForm);
                }

            } catch (\Exception $e) {

            }

        }
    }
}
