<?php

declare(strict_types=1);

error_reporting(-1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
define('YII_ENV', 'test');

$_SERVER['SCRIPT_NAME'] = '/' . __DIR__;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@glpzzz/otellog', dirname(__DIR__) . '/src');
Yii::setAlias('@glpzzz/otellog/tests', __DIR__);
