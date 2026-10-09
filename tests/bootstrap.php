<?php

declare(strict_types=1);

// Loads the Craft and Yii classes without booting a Craft application, which
// is all the transport and adapter need.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
