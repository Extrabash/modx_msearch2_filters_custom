# modx_msearch2_filters_custom
Реализует функционал логики вместо ИЛИ -> И для стандартных чекбоксов

1. Меняем класс обработчик фильтров. Идем в настройки системы и в настройках mSearch2 меняем параметр mse2_filters_handler_class на CustomFilter

2. Теперь нам нужно создать сам класс. Для этого создаем файл core/components/msearch2/custom/filters/custom.class.php ...

https://ilyaut.ru/reposts/mfilter2-principle-or-change-to-and/
