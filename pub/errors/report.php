<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

require_once 'processorFactory.php';

$processorFactory = new \Magento\Framework\Error\ProcessorFactory();
$processor = $processorFactory->createProcessor();

if (isset($reportData) && is_array($reportData)) {
    $reportUrl = $processor->saveReport($reportData);
    if (headers_sent()) {
        echo '<scripts type="text/javascript">';
        echo "window.location.href = '{$reportUrl}';";
        echo '</scripts>';
        exit;
    }
}

$response = $processor->processReport();
$response->sendResponse();
