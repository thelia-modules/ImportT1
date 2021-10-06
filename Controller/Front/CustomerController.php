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
use Symfony\Component\Config\Definition\Builder\ValidationBuilder;
use Symfony\Component\Form\FormFactoryBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ValidatorBuilder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Controller\Front\BaseFrontController;
use Front\Controller\CustomerController as BaseCustomerController;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Form\CustomerLogin;
use Thelia\Log\Tlog;
use Thelia\Model\CustomerQuery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class CustomerController
 * @package ImportT1\Controller\Front
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 */
class CustomerController extends BaseFrontController
{
    /**
     * @Route("/login", name="importT1_customer_login_process", methods="POST")
     * @param RequestStack $requestStack
     * @param SecurityContext $securityContext
     * @param EventDispatcherInterface $eventDispatcher
     * @param TranslatorInterface $translator
     * @param FormFactoryBuilderInterface $formFactoryBuilder
     * @param ValidatorBuilder $validationBuilder
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\Response|null
     */
    public function loginAction(RequestStack $requestStack, SecurityContext $securityContext, EventDispatcherInterface $eventDispatcher, TranslatorInterface $translator, FormFactoryBuilderInterface $formFactoryBuilder, ValidatorBuilder $validationBuilder)
    {
        $customerController = new BaseCustomerController();
        $customerController->setContainer($this->container);
        $response = $customerController->loginAction($eventDispatcher);

        if (! $securityContext->hasCustomerUser()) {

            $request = $requestStack->getCurrentRequest();
            $customerLoginForm = new CustomerLogin($request, $eventDispatcher, $translator, $formFactoryBuilder, $validationBuilder);

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

                    if (null !== $customer) {
                    $customer->setPassword($form->get('password')->getData())
                        ->save();

                    $customerTemp
                        ->setProcessed(true)
                            ->save();;

                    $eventDispatcher->dispatch(new CustomerLoginEvent($customer), TheliaEvents::CUSTOMER_LOGIN);
                    
                    $successUrl = $customerLoginForm->getSuccessUrl();

                    $response = RedirectResponse::create($successUrl);
                }
                }
            } catch (\Exception $e) {
                Tlog::getInstance()->error($e->getMessage());
            }

        }
        
        return $response;
    }
}
