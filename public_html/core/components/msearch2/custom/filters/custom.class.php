<?php

if(!class_exists('msDeliveryInterface')) {
    require_once dirname(dirname(dirname(__DIR__))) . '/minishop2/model/minishop2/msdeliveryhandler.class.php';
}

class msCDEKHandler extends msDeliveryHandler implements msDeliveryInterface{
    
    public function getCost(msOrderInterface $order, msDelivery $delivery, $cost = 0) {
        
        $delivery_cost = 0;
        $log = [];

        if (empty($this->ms2)) {
            $this->ms2 = $this->modx->getService('miniShop2');
        }
        if (empty($this->ms2->cart)) {
            $this->ms2->loadServices($this->ms2->config['ctx']);
        }
        
        $ms_CDEK2 = $this->modx->getService('ms_CDEK2', 'ms_CDEK2', MODX_CORE_PATH . 'components/ms_cdek2/model/', []);
        
        $cart = $order->ms2->cart->get();
        if (empty($cart)) {
            $this->modx->log(MODX::LOG_LEVEL_ERROR, '[ms_CDEK2] Could not calculate - cart is empty.');
            return 0;
        }
        
        $cart_weight = 0;
        foreach ($cart as $key => &$data) {
            if (empty($data['mscdek_size'])) {
                $data['mscdek_size'] = [1, 1, 1];
                if ($product = $this->modx->getObject('msProductData', $data['id'])) {
                    if (!empty($product->get('mscdek_size'))) {
                        $value = str_replace(['х', '×', 'X', 'Х'], 'x', $product->get('mscdek_size'));
                        $size = explode('x', $value);
                        for($i = 0; $i <= 2; $i++) {
                            if (isset($size[$i])) {
                                $data['mscdek_size'][$i] = intval($size[$i]);
                            }
                        }
                    }
                }
            }
            if (!empty($data['weight'])) {
                $cart_weight += $data['weight'] * $data['count'];
            }
        }
        
        $order->ms2->cart->set($cart);
        $order = $order->ms2->order->get();
        
        if (!empty($cart)) {
            $from_location = $ms_CDEK2->getLocation(['postal_code' => $ms_CDEK2->config['sender_index']]);
            
            $query = [];
            if ($order['index']) {
                $to_location = $ms_CDEK2->getLocation(['postal_code' => $order['index']]);
            }
            if (!$to_location) {
                if ($order['city']) {
                    $to_location = $ms_CDEK2->getLocation(['city' => $order['city']]);
                }
            }
            if (!$to_location) {
                $order['index'] = $ms_CDEK2->config['sender_index'];
                $to_location = $ms_CDEK2->getLocation(['postal_code' => $order['index']]);
            }
            
            $packages = [];
            $weight = 0;
            foreach ($cart as $product) {
                for ($i = 0; $i < $product['count']; $i++) {
                    $packages[] = [
                        'length' => round($product['mscdek_size'][0] * $ms_CDEK2->config['size_multiplier']),
                        'width'  => round($product['mscdek_size'][1] * $ms_CDEK2->config['size_multiplier']),
                        'height' => round($product['mscdek_size'][2] * $ms_CDEK2->config['size_multiplier']),
                        'weight' => round($product['weight'] * $ms_CDEK2->config['weight_multiplier']),
                    ];
                    $weight += round($product['weight'] * $ms_CDEK2->config['weight_multiplier']);
                }
            }
            if (!$weight) {
                $weight = 1000;
            }
            $response = $ms_CDEK2->makeRequest('calculator/tarifflist', [
                'from_location' => $from_location,
                'to_location' => $to_location,
                'packages' => $packages
            ]);

            $tarifs_available = [];
            $properties = $delivery->get('properties');
            if (!empty($properties)) {
                if (isset($properties['ms_cdek2_tarif'])) {
                    $selected = intval($properties['ms_cdek2_tarif']);
                }
                if (isset($properties['ms_cdek2_tarifpvz'])) {
                    $selected = intval($properties['ms_cdek2_tarifpvz']);
                }
                if ($selected) {
                    $tarifs_available[] = $selected;
                }
            }
            if (!empty($response['tariff_codes'])) {
                $delivery_modes = [];
                $log[] = '[ms_CDEK2] =====================';
                switch ($delivery->get('class')) {
                    case 'msCDEKHandler':
                        $delivery_modes = [1, 3];
                        break;
                    case 'msCDEKHandlerPVZ':
                        $delivery_modes = [2, 4, 6, 7];
                        break;
                    default:
                        $delivery_modes = [2, 4, 6, 7];
                        break;
                }

                $log[] = 'Set available delivery modes - ' . print_r($delivery_modes, true);
                
                $min_price = 0;
                $max_price = 0;
                $min_period = 0;
                $max_period = 0;
                /*
                if (empty($tarifs_available) && !empty($ms_CDEK2->config['tariffs'])) {
                    $tarifs_available = explode(',', $ms_CDEK2->config['tariffs']);
                }
                $log[] = 'Set tariff - ' . print_r($tarifs_available, true);
                */
                //$selectedTarif = $ms_CDEK2->config['tariffs'];
                ray('Выбранный тариф - '.$selected)->label('SDEK');
                
                foreach ($response['tariff_codes'] as $tariff) 
                {
                    
                    //if (!empty($tarifs_available) && in_array($tariff['tariff_code'], $tarifs_available)) {
                    if($selected == $tariff['tariff_code'])
                    {
                        ray($tariff)->label('SDEK');

                        $log[] = 'Check ' . print_r($tariff, true);
                        $log[] = 'Tariff ' . $tariff['tariff_code'] . ' is in available list';
                        
                        if ($max_price < $tariff['delivery_sum']) {
                            $max_price = $tariff['delivery_sum'];
                        }

                        if (in_array($tariff['delivery_mode'], $delivery_modes)) 
                        {
                            if (!$min_price) {
                                $min_price = $tariff['delivery_sum'];
                            }
                            if ($min_price >= $tariff['delivery_sum']) {
                                $less_price = ($min_price > $tariff['delivery_sum']);
                                $same_price_less_period = ($min_price == $tariff['delivery_sum'] && $min_period > $tariff['period_min']);
                                if (!$min_period || $less_price || $same_price_less_period) {
                                    $selected = $tariff['tariff_code'];
                                    $min_price = $tariff['delivery_sum'];
                                    $min_period = $tariff['period_min'];
                                    $max_period = $tariff['period_max'];
                                }
                            }
                        } 
                        else 
                        {
                            $log[] = 'Delivery mode ' . $tariff['delivery_mode'] . ' not in available list';
                        }

                    }

                    
                }
                if ($min_price) {
                    $delivery_cost = $min_price;
                } else {
                    $delivery_cost = $max_price;
                }
            }
        }
        $log[] = 'Selected tariff = ' . $selected;
        $log[] = 'Delivery cost = ' . $delivery_cost;
        
        if ($selected && !$delivery_cost) {
            $response = $ms_CDEK2->makeRequest('calculator/tariff', [
                'tariff_code' => $selected,
                'code' => '',
                'weight' => $weight,
                'from_location' => $from_location,
                'to_location' => $to_location,
                'packages' => $packages
            ]);
            if (!empty($response['total_sum'])) {
                $delivery_cost = $response['total_sum'];
            }
        }

        if (!$delivery_cost) {
            $message = [];
            if (!empty($response['errors'])) {
                foreach ($response['errors'] as $error) {
                    $message[] = $error['message'];
                }
            }
            $_SESSION['ms_CDEK2']['success'] = false;
            $_SESSION['ms_CDEK2']['status'] = $this->modx->lexicon('ms_cdek2_error') . ': ' . implode('; ', $message);
            if ($ms_CDEK2->config['debug']) {
                $this->modx->log(MODX::LOG_LEVEL_ERROR, implode(PHP_EOL, $log));
            }
            return $cost;
        }

        $add_price = $delivery->get('price');
        $log[] = 'Add price = ' . $add_price;
        if (preg_match('/%$/', $add_price)) {
            $add_price = str_replace('%', '', $add_price);
            $delivery_cost += $delivery_cost / 100 * $add_price;
        } else {
            $delivery_cost += $add_price;
        }
        $log[] = 'Delivery cost = ' . $delivery_cost;
        $log[] = 'Cart = ' . print_r($cart, true);
        $weight_price = $delivery->get('weight_price');
        $delivery_cost += $weight_price * $cart_weight;

        $log[] = 'Weight price = ' . $weight_price;
        $log[] = 'Cart weight = ' . $cart_weight;
        $log[] = 'Delivery cost = ' . $delivery_cost;

        $cart = $this->ms2->cart->status();
        $free_delivery_amount = $delivery->get('free_delivery_amount');
        if ($free_delivery_amount > 0 && $free_delivery_amount <= $cart['total_cost']) {
            $delivery_cost = 0;

            $log[] = 'Free delivery amount = ' . $free_delivery_amount;
            $log[] = 'Cart cost = ' . $cart['total_cost'];
            $log[] = 'Delivery cost = ' . $delivery_cost;
        }
        
        $rounding = $this->modx->getOption('ms_cdek2_rounding', null, 'none', true);
        $log[] = 'Rounding method = ' . $rounding;
        switch ($rounding) {
            case 'round':
                $delivery_cost = round($delivery_cost);
                break;
            case 'ceil':
                $delivery_cost = ceil($delivery_cost);
                break;
            case 'floor':
                $delivery_cost = floor($delivery_cost);
                break;
            default:
                break;
        }
        $status_data = [
            'price' => $delivery_cost,
            'min' => $min_period,
            'max' => $max_period
        ];
        $log[] = 'Delivery cost status = ' . print_r($status_data, true);
        
        $_SESSION['ms_CDEK2']['success'] = true;
        $_SESSION['ms_CDEK2']['status'] = $this->modx->lexicon('ms_cdek2_status', $status_data);

        if ($ms_CDEK2->config['debug']) {
            $this->modx->log(MODX::LOG_LEVEL_ERROR, implode(PHP_EOL, $log));
        }

        $extra_charge= $this->modx->getOption('extra_charge','');
        $extra_charge=$extra_charge/100;
        $package=$this->modx->getOption('package','');
        $insurance_sdek=$this->modx->getOption('insurance_sdek','');
        $array=$_SESSION['minishop2']['cart'];

        foreach($array as $item)
        {
            $this->temp_cost+=(int)$item['price']*(int)$item['count'];
        }
        $price_plus=($this->temp_cost*$insurance_sdek+$package)*$extra_charge;

        ray('price_plus ' . $price_plus)->label('SDEK');

        $delivery_cost=round($delivery_cost+$price_plus);
        
        ray()->trace();

        ray('Финальная стоимость доставки -> ' . $delivery_cost)->label('SDEK')->color('red');

        return $cost + $delivery_cost;
    }
}
