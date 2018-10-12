<?php

namespace Dynamic\FoxyStripe\Page;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

class OrderHistoryPage extends \Page
{
    /**
     * @var string
     */
    private static $singular_name = 'Order History Page';

    /**
     * @var string
     */
    private static $plural_name = 'Order History Pages';

    /**
     * @var string
     */
    private static $description = 'Show a customers past orders. Requires authentication';

    /**
     * return all current Member's Orders.
     *
     * @param int $limit
     *
     * @return bool|PaginatedList
     *
     * @throws \Exception
     */
    public function getOrders($limit = 10)
    {
        if ($Member = Security::getCurrentUser()) {
            $Orders = $Member->Orders()->sort('TransactionDate', 'DESC');

            $list = new PaginatedList($Orders, Controller::curr()->getRequest());
            $list->setPageLength($limit);

            return $list;
        }

        return false;
    }
}
