<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Model\Express;

use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\ObjectManagerInterface;
use Magento\Paypal\Model\Api\Nvp;
use Magento\Paypal\Model\Api\Type\Factory;
use Magento\Paypal\Model\Config;
use Magento\Paypal\Model\Info;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\ResourceModel\Quote\Collection;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Class CheckoutTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CheckoutTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Info|\PHPUnit_Framework_MockObject_MockObject
     */
    private $paypalInfo;

    /**
     * @var Config|\PHPUnit_Framework_MockObject_MockObject
     */
    private $paypalConfig;

    /**
     * @var Factory|\PHPUnit_Framework_MockObject_MockObject
     */
    private $apiTypeFactory;

    /**
     * @var Nvp|\PHPUnit_Framework_MockObject_MockObject
     */
    private $api;

    /**
     * @var Checkout
     */
    private $checkoutModel;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp()
    {
        $this->objectManager = Bootstrap::getObjectManager();

        $this->paypalInfo = $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->paypalConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->api = $this->getMockBuilder(Nvp::class)
            ->disableOriginalConstructor()
            ->setMethods(['call', 'getExportedShippingAddress', 'getExportedBillingAddress'])
            ->getMock();

        $this->api->expects($this->any())
            ->method('call')
            ->will($this->returnValue([]));

        $this->apiTypeFactory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->apiTypeFactory->expects($this->any())
            ->method('create')
            ->will($this->returnValue($this->api));
    }

    /**
     * Verify that api has set customer email.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testCheckoutStartWithBillingAddress()
    {
        $quote = $this->getFixtureQuote();
        $paypalConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $apiTypeFactory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $paypalInfo = $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->getMock();

        $checkoutModel = $this->objectManager->create(
            Checkout::class,
            [
                'params' => ['quote' => $quote, 'config' => $paypalConfig],
                'apiTypeFactory' => $apiTypeFactory,
                'paypalInfo' => $paypalInfo
            ]
        );

        $api = $this->getMockBuilder(Nvp::class)
            ->disableOriginalConstructor()
            ->setMethods(['callSetExpressCheckout'])
            ->getMock();

        $api->expects($this->any())
            ->method('callSetExpressCheckout')
            ->will($this->returnValue(null));

        $apiTypeFactory->expects($this->any())
            ->method('create')
            ->will($this->returnValue($api));

        $checkoutModel->start(
            'return',
            'cancel',
            false
        );

        $this->assertEquals('test@com.com', $api->getBillingAddress()->getEmail());
    }

    /**
     * Verify that an order placed with an existing customer can re-use the customer addresses.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testPrepareCustomerQuote()
    {
        /** @var Quote $quote */
        $quote = $this->getFixtureQuote();
        $quote->setCheckoutMethod(Onepage::METHOD_CUSTOMER); // to dive into _prepareCustomerQuote() on switch
        $quote->getShippingAddress()->setSameAsBilling(0);
        $quote->setReservedOrderId(null);
        $customer = $this->objectManager->create(\Magento\Customer\Model\Customer::class)->load(1);
        $customer->setDefaultBilling(false)
            ->setDefaultShipping(false)
            ->save();

        /** @var \Magento\Customer\Model\Session $customerSession */
        $customerSession = $this->objectManager->get(\Magento\Customer\Model\Session::class);
        $customerSession->loginById(1);
        $checkout = $this->getCheckout($quote);
        $checkout->place('token');

        /** @var \Magento\Customer\Api\CustomerRepositoryInterface $customerService */
        $customerService = $this->objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $customer = $customerService->getById($quote->getCustomerId());

        $this->assertEquals(1, $quote->getCustomerId());
        $this->assertEquals(2, count($customer->getAddresses()));

        $this->assertEquals(1, $quote->getBillingAddress()->getCustomerAddressId());
        $this->assertEquals(2, $quote->getShippingAddress()->getCustomerAddressId());

        $order = $checkout->getOrder();
        $this->assertEquals(1, $order->getBillingAddress()->getCustomerAddressId());
        $this->assertEquals(2, $order->getShippingAddress()->getCustomerAddressId());
    }

    /**
     * Verify that after placing the order, addresses are associated with the order and the quote is a guest quote.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testPlaceGuestQuote()
    {
        /** @var Quote $quote */
        $quote = $this->getFixtureQuote();
        $quote->setCheckoutMethod(Onepage::METHOD_GUEST); // to dive into _prepareGuestQuote() on switch
        $quote->getShippingAddress()->setSameAsBilling(0);
        $quote->setReservedOrderId(null);

        $checkout = $this->getCheckout($quote);
        $checkout->place('token');

        $this->assertNull($quote->getCustomerId());
        $this->assertTrue($quote->getCustomerIsGuest());
        $this->assertEquals(
            \Magento\Customer\Model\GroupManagement::NOT_LOGGED_IN_ID,
            $quote->getCustomerGroupId()
        );

        $this->assertNotEmpty($quote->getBillingAddress());
        $this->assertNotEmpty($quote->getShippingAddress());

        $order = $checkout->getOrder();
        $this->assertNotEmpty($order->getBillingAddress());
        $this->assertNotEmpty($order->getShippingAddress());
    }

    /**
     * @param Quote $quote
     * @return Checkout
     */
    private function getCheckout(Quote $quote)
    {
        return $this->objectManager->create(
            Checkout::class,
            [
                'params' => [
                    'config' => $this->getMockBuilder(Config::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'quote' => $quote,
                ]
            ]
        );
    }

    /**
     * Verify that an order placed with an existing customer can re-use the customer addresses.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testReturnFromPaypal()
    {
        $quote = $this->getFixtureQuote();
        $this->checkoutModel = $this->objectManager->create(
            Checkout::class,
            [
                'params' => ['quote' => $quote, 'config' => $this->paypalConfig],
                'apiTypeFactory' => $this->apiTypeFactory,
                'paypalInfo' => $this->paypalInfo
            ]
        );

        $prefix = 'exported';
        $exportedBillingAddress = $this->getExportedAddressFixture($quote->getBillingAddress()->getData(), $prefix);
        $this->api->expects($this->any())
            ->method('getExportedBillingAddress')
            ->will($this->returnValue($exportedBillingAddress));

        $exportedShippingAddress = $this->getExportedAddressFixture($quote->getShippingAddress()->getData(), $prefix);
        $this->api->expects($this->any())
            ->method('getExportedShippingAddress')
            ->will($this->returnValue($exportedShippingAddress));

        $this->paypalInfo->expects($this->once())->method('importToPayment')->with($this->api, $quote->getPayment());

        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 1);

        $this->checkoutModel->returnFromPaypal('token');

        $billingAddress = $quote->getBillingAddress();
        $this->assertContains($prefix, $billingAddress->getFirstname());
        $this->assertEquals('note', $billingAddress->getCustomerNote());

        $shippingAddress = $quote->getShippingAddress();
        $this->assertTrue((bool)$shippingAddress->getSameAsBilling());
        $this->assertNull($shippingAddress->getPrefix());
        $this->assertNull($shippingAddress->getMiddlename());
        $this->assertNull($shippingAddress->getLastname());
        $this->assertNull($shippingAddress->getSuffix());
        $this->assertTrue($shippingAddress->getShouldIgnoreValidation());
        $this->assertContains('exported', $shippingAddress->getFirstname());
        $paymentAdditionalInformation = $quote->getPayment()->getAdditionalInformation();
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_SHIPPING_METHOD, $paymentAdditionalInformation);
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_PAYER_ID, $paymentAdditionalInformation);
        $this->assertArrayHasKey(Checkout::PAYMENT_INFO_TRANSPORT_TOKEN, $paymentAdditionalInformation);
        $this->assertTrue($quote->getPayment()->hasMethod());
        $this->assertTrue($quote->getTotalsCollectedFlag());
    }

    /**
     * The case when handling address data from Paypal button.
     * System's address fields are replacing from export Paypal data.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testReturnFromPaypalButton()
    {
        $quote = $this->getFixtureQuote();
        $this->prepareCheckoutModel($quote);
        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 1);

        $this->checkoutModel->returnFromPaypal('token');

        $shippingAddress = $quote->getShippingAddress();

        $prefix = '';
        $this->assertEquals([$prefix . $this->getExportedData()['street']], $shippingAddress->getStreet());
        $this->assertEquals($prefix . $this->getExportedData()['firstname'], $shippingAddress->getFirstname());
        $this->assertEquals($prefix . $this->getExportedData()['city'], $shippingAddress->getCity());
        $this->assertEquals($prefix . $this->getExportedData()['telephone'], $shippingAddress->getTelephone());
        $this->assertEquals($prefix . $this->getExportedData()['email'], $shippingAddress->getEmail());
    }

    /**
     * The case when handling address data from the checkout.
     * System's address fields are not replacing from export PayPal data.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express_with_customer.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testReturnFromPaypalIfCheckout()
    {
        $quote = $this->getFixtureQuote();
        $this->prepareCheckoutModel($quote);
        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 0);

        $this->checkoutModel->returnFromPaypal('token');

        $shippingAddress = $quote->getShippingAddress();

        $prefix = 'exported';

        $this->assertNotEquals([$prefix . $this->getExportedData()['street']], $shippingAddress->getStreet());
        $this->assertNotEquals($prefix . $this->getExportedData()['firstname'], $shippingAddress->getFirstname());
        $this->assertNotEquals($prefix . $this->getExportedData()['city'], $shippingAddress->getCity());
        $this->assertNotEquals($prefix . $this->getExportedData()['telephone'], $shippingAddress->getTelephone());
    }

    /**
     * Test case when customer doesn't have either billing or shipping addresses.
     * Customer add virtual product to quote and place order using PayPal Express method.
     * After return from PayPal quote billing address have to be updated by PayPal Express address.
     *
     * @magentoDataFixture Magento/Paypal/_files/virtual_quote_with_empty_billing_address.php
     * @magentoConfigFixture current_store payment/paypal_express/active 1
     * @magentoDbIsolation enabled
     */
    public function testReturnFromPaypalForCustomerWithEmptyAddresses()
    {
        $quote = $this->getFixtureQuote();
        $this->prepareCheckoutModel($quote);
        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 0);

        $this->checkoutModel->returnFromPaypal('token');

        $billingAddress = $quote->getBillingAddress();

        $this->performQuoteAddressAssertions($billingAddress, $this->getExportedData());
    }

    /**
     * Test case when customer doesn't have either billing or shipping addresses.
     * Customer add virtual product to quote and place order using PayPal Express method.
     * Default store country is in PayPal Express allowed specific country list.
     *
     * @magentoDataFixture Magento/Paypal/_files/virtual_quote_with_empty_billing_address.php
     * @magentoConfigFixture current_store payment/paypal_express/active 1
     * @magentoConfigFixture current_store payment/paypal_express/allowspecific 1
     * @magentoConfigFixture current_store payment/paypal_express/specificcountry US,GB
     * @magentoConfigFixture current_store general/country/default US
     *
     * @magentoDbIsolation enabled
     */
    public function testPaymentValidationWithAllowedSpecificCountry()
    {
        $quote = $this->getFixtureQuote();
        $this->prepareCheckoutModel($quote);

        $quote->getPayment()->getMethodInstance()->validate();
    }

    /**
     * Test case when customer doesn't have either billing or shipping addresses.
     * Customer add virtual product to quote and place order using PayPal Express method.
     * PayPal Express allowed specific country list doesn't contain default store country.
     *
     * @magentoDataFixture Magento/Paypal/_files/virtual_quote_with_empty_billing_address.php
     * @magentoConfigFixture current_store payment/paypal_express/active 1
     * @magentoConfigFixture current_store payment/paypal_express/allowspecific 1
     * @magentoConfigFixture current_store payment/paypal_express/specificcountry US,GB
     * @magentoConfigFixture current_store general/country/default CA
     *
     * @magentoDbIsolation enabled
     * @expectedException \Magento\Framework\Exception\LocalizedException
     * @expectedExceptionMessage You can't use the payment type you selected to make payments to the billing country.
     */
    public function testPaymentValidationWithAllowedSpecificCountryNegative()
    {
        $quote = $this->getFixtureQuote();
        $this->prepareCheckoutModel($quote);

        $quote->getPayment()->getMethodInstance()->validate();
    }

    /**
     * Performs quote address assertions.
     *
     * @param Address $address
     * @param array $expected
     * @return void
     */
    private function performQuoteAddressAssertions(Address $address, array $expected)
    {
        foreach ($expected as $key => $item) {
            $methodName = 'get' . ucfirst($key);
            if ($key == 'street') {
                $item = [$item];
            }
            $this->assertEquals($item, $address->$methodName(), 'The "'. $key . '" does not match.');
        }
    }

    /**
     * Initialize a checkout model mock.
     *
     * @param Quote $quote
     */
    private function prepareCheckoutModel(Quote $quote)
    {
        $this->checkoutModel = $this->objectManager->create(
            Checkout::class,
            [
                'params'         => ['quote' => $quote, 'config' => $this->paypalConfig],
                'apiTypeFactory' => $this->apiTypeFactory,
                'paypalInfo'     => $this->paypalInfo
            ]
        );

        $exportedBillingAddress = $this->getExportedAddressFixture($this->getExportedData());
        $this->api->method('getExportedBillingAddress')
            ->will($this->returnValue($exportedBillingAddress));

        $exportedShippingAddress = $this->getExportedAddressFixture($this->getExportedData());
        $this->api->method('getExportedShippingAddress')
            ->will($this->returnValue($exportedShippingAddress));

        $this->paypalInfo->method('importToPayment')
            ->with($this->api, $quote->getPayment());
    }

    /**
     * A Paypal response stub.
     *
     * @return array
     */
    private function getExportedData()
    {
        return [
            'email'      => 'customer@example.com',
            'firstname'  => 'John',
            'lastname'   => 'Doe',
            'country'    => 'US',
            'region'     => 'Colorado',
            'region_id'  => '13',
            'city'       => 'Denver',
            'street'     => '66 Pearl St',
            'postcode'   => '80203',
            'telephone'  => '555-555-555',
        ];
    }

    /**
     * Verify that guest customer quota has set type of checkout.
     *
     * @magentoDataFixture Magento/Paypal/_files/quote_payment_express.php
     * @magentoAppIsolation enabled
     * @magentoDbIsolation enabled
     */
    public function testGuestReturnFromPaypal()
    {
        $quote = $this->getFixtureQuote();
        $paypalConfig = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $apiTypeFactory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $paypalInfo = $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->setMethods(['importToPayment'])
            ->getMock();

        $checkoutModel = $this->objectManager->create(
            Checkout::class,
            [
                'params' => ['quote' => $quote, 'config' => $paypalConfig],
                'apiTypeFactory' => $apiTypeFactory,
                'paypalInfo' => $paypalInfo
            ]
        );

        $api = $this->getMockBuilder(Nvp::class)
            ->disableOriginalConstructor()
            ->setMethods(['call', 'getExportedShippingAddress', 'getExportedBillingAddress'])
            ->getMock();

        $api->expects($this->any())
            ->method('call')
            ->will($this->returnValue([]));

        $apiTypeFactory->expects($this->any())
            ->method('create')
            ->will($this->returnValue($api));

        $exportedBillingAddress = $this->getExportedAddressFixture($quote->getBillingAddress()->getData());
        $api->expects($this->any())
            ->method('getExportedBillingAddress')
            ->will($this->returnValue($exportedBillingAddress));

        $exportedShippingAddress = $this->getExportedAddressFixture($quote->getShippingAddress()->getData());
        $api->expects($this->any())
            ->method('getExportedShippingAddress')
            ->will($this->returnValue($exportedShippingAddress));

        $paypalInfo->expects($this->once())
            ->method('importToPayment')
            ->with($api, $quote->getPayment());

        $quote->getPayment()->setAdditionalInformation(Checkout::PAYMENT_INFO_BUTTON, 1);

        $checkoutModel->returnFromPaypal('token');

        $this->assertEquals(Onepage::METHOD_GUEST, $quote->getCheckoutMethod());
    }

    /**
     * Prepare fixture for exported address.
     *
     * @param array $addressData
     * @param string $prefix
     * @return \Magento\Framework\DataObject
     */
    private function getExportedAddressFixture(array $addressData, string $prefix = '') :\Magento\Framework\DataObject
    {
        $addressDataKeys = [
            'country',
            'firstname',
            'lastname',
            'street',
            'city',
            'telephone',
            'postcode',
            'region',
            'region_id',
            'email'
        ];
        $result = [];
        foreach ($addressDataKeys as $key) {
            if (isset($addressData[$key])) {
                $result[$key] = $prefix . $addressData[$key];
            }
        }

        $fixture = new \Magento\Framework\DataObject($result);
        $fixture->setExportedKeys($addressDataKeys);
        $fixture->setData('note', 'note');

        return $fixture;
    }

    /**
     * Gets quote.
     *
     * @return Quote
     */
    private function getFixtureQuote()
    {
        /** @var Collection $quoteCollection */
        $quoteCollection = $this->objectManager->create(Collection::class);

        return $quoteCollection->getLastItem();
    }
}