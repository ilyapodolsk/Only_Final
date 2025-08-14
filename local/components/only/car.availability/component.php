<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

// Подключаем модули
if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('highloadblock')) {
    echo '<p style="color: red">Модули iblock или highloadblock не подключены</p>';
    return;
}

global $USER;
$userId = (int)$USER->GetID();

if (!$userId) {
    echo '<p style="color: red">Пользователь не авторизован</p>';
    return;
}

// Получаем параметры даты/времени
$startTimeStr = trim($_GET['start'] ?? '');
$endTimeStr = trim($_GET['end'] ?? '');

if (empty($startTimeStr)) {
    echo '<p style="color: red">Не передан параметр "start"</p>';
    return;
}
if (empty($endTimeStr)) {
    echo '<p style="color: red">Не передан параметр "end"</p>';
    return;
}

// Парсим даты/время разных форматов
$startTime = 
    DateTime::createFromFormat('Y-m-d H:i', $startTimeStr)
    ?: DateTime::createFromFormat('Y-m-d H:i:s', $startTimeStr)
    ?: DateTime::createFromFormat('Y.m.d H:i', $startTimeStr)
    ?: DateTime::createFromFormat('Y.m.d H:i:s', $startTimeStr)
    ?: DateTime::createFromFormat('d.m.Y H:i', $startTimeStr)
    ?: DateTime::createFromFormat('d.m.Y H:i:s', $startTimeStr)
    ?: DateTime::createFromFormat('d-m-Y H:i', $startTimeStr)
    ?: DateTime::createFromFormat('d-m-Y H:i:s', $startTimeStr);

$endTime = 
    DateTime::createFromFormat('Y-m-d H:i', $endTimeStr)
    ?: DateTime::createFromFormat('Y-m-d H:i:s', $endTimeStr)
    ?: DateTime::createFromFormat('Y.m.d H:i', $endTimeStr)
    ?: DateTime::createFromFormat('Y.m.d H:i:s', $endTimeStr)
    ?: DateTime::createFromFormat('d.m.Y H:i', $endTimeStr)
    ?: DateTime::createFromFormat('d.m.Y H:i:s', $endTimeStr)
    ?: DateTime::createFromFormat('d-m-Y H:i', $endTimeStr)
    ?: DateTime::createFromFormat('d-m-Y H:i:s', $endTimeStr);

if (!$startTime || !$endTime || $startTime >= $endTime) {
    echo '<p style="color: red">Некорректный временной интервал</p>';
    return;
}

// Форматируем даты/время в формате, понятном Хайлоадблоку
$startFormatted = $startTime->format('d.m.Y H:i:s');
$endFormatted = $endTime->format('d.m.Y H:i:s');

// Получаем пользователя
$user = CUser::GetByID($userId)->Fetch();
if (!$user || !$user['WORK_POSITION']) {
    echo '<p style="color: red">У пользователя не указана должность</p>';
    return;
}

// Получаем должность и доступные категории комфорта
$positionName = $user['WORK_POSITION'];
$positionRes = CIBlockElement::GetList(
    [],
    [
        'IBLOCK_ID' => 4,
        'NAME' => $positionName
    ],
    false,
    ['nTopCount' => 1],
    ['PROPERTY_COMFORT_CATEGORIES']
);

if (!($position = $positionRes->Fetch())) {
    echo '<p style="color: red">Должность не найдена</p>';
    return;
}

$allowedCategoryIds = is_array($position['PROPERTY_COMFORT_CATEGORIES_VALUE'])
    ? $position['PROPERTY_COMFORT_CATEGORIES_VALUE']
    : [$position['PROPERTY_COMFORT_CATEGORIES_VALUE']];

if (empty($allowedCategoryIds)) {
    echo '<p style="color: red">Нет доступных категорий комфорта</p>';
    return;
}

// Получаем автомобили
$carRes = CIBlockElement::GetList(
    ['NAME' => 'ASC'],
    [
        'IBLOCK_ID' => 6,
        'PROPERTY_COMFORT_CATEGORY' => $allowedCategoryIds
    ],
    false,
    false,
    ['ID', 'NAME', 'PROPERTY_COMFORT_CATEGORY', 'PROPERTY_DRIVER']
);

$cars = [];
while ($car = $carRes->Fetch()) {
    $cars[$car['ID']] = [
        'MODEL' => $car['NAME'],
        'COMFORT_CATEGORY' => $car['PROPERTY_COMFORT_CATEGORY_VALUE'],
        'DRIVER_ID' => $car['PROPERTY_DRIVER_VALUE'],
        'IS_AVAILABLE' => true,
    ];
}

// Получаем водителей
$driverIds = array_filter(array_column($cars, 'DRIVER_ID'));
$drivers = [];

if (!empty($driverIds)) {
    $driverRes = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => 5,
            'ID' => $driverIds
        ],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($driver = $driverRes->Fetch()) {
        $drivers[$driver['ID']] = $driver['NAME'];
    }
}

// Получаем категории комфорта
$categoryRes = CIBlockElement::GetList(
    [],
    [
        'IBLOCK_ID' => 3,
        'ID' => $allowedCategoryIds
    ],
    false,
    false,
    ['ID', 'NAME']
);
$categories = [];
while ($cat = $categoryRes->Fetch()) {
    $categories[$cat['ID']] = $cat['NAME'];
}

// Получаем бронирования из Хайлоадблока
$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(1)->fetch();
if (!$hlblock) {
    echo '<p style="color: red">Хайлоадблок не найден</p>';
    return;
}

$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
$entityDataClass = $entity->getDataClass();

$bookingsRes = $entityDataClass::getList([
    'select' => ['UF_CAR_ID', 'UF_START_TIME', 'UF_END_TIME'],
    'filter' => [
        'UF_CAR_ID' => array_keys($cars),
        '<UF_START_TIME' => $endFormatted,
        '>UF_END_TIME' => $startFormatted
    ]
]);

// Отмечаем занятые автомобили
while ($booking = $bookingsRes->fetch()) {
    $carId = $booking['UF_CAR_ID'];
    if (isset($cars[$carId])) {
        $cars[$carId]['IS_AVAILABLE'] = false;
    }
}

// Формируем результат
$availableCars = [];
foreach ($cars as $car) {
    if ($car['IS_AVAILABLE']) {
        $availableCars[] = [
            'MODEL' => $car['MODEL'],
            'COMFORT_CATEGORY' => $categories[$car['COMFORT_CATEGORY']] ?? 'Неизвестно',
            'DRIVER' => $drivers[$car['DRIVER_ID']] ?? 'Неизвестно',
        ];
    }
}

// ШАБЛОН 
if (!empty($arResult['ERROR'])) {
    echo '<p style="color: red">' . htmlspecialchars($arResult['ERROR']) . '</p>';
} else {
    echo '<p>Доступные автомобили с ' . htmlspecialchars($startTimeStr) . ' по ' . htmlspecialchars($endTimeStr) . ':</p>';
    if (count($availableCars) > 0) {
        echo '<ul>';
        foreach ($availableCars as $car) {
            echo '<li><strong>' . htmlspecialchars($car['MODEL']) . '</strong> (' . htmlspecialchars($car['COMFORT_CATEGORY']) . '), водитель: ' . htmlspecialchars($car['DRIVER']) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>Нет доступных автомобилей.</p>';
    }
}