<?php
class CustomFilter extends mse2FiltersHandler {

	public function buildDefaultMyFilter(array $values, $name = '') {
		if (count($values) < 2 && empty($this->config['showEmptyFilters'])) {
			return array();
		}		

		$results = array();
		foreach ($values as $value => $ids) {
			$results[$value] = array(
				'title' => $value
				,'value' => $value
				,'type' => 'default'
				,'resources' => $ids
			);
		}
		ksort($results);

		return $results;
	}
	

    public function filterDefaultMy(array $requested, array $values, array $ids) {

		$matched = array();
		$tmp = array_flip($ids);
		foreach ($requested as $value) {
            $value = str_replace('"', '&quot;', $value);
			if (isset($values[$value])) {
				$resources = $values[$value];
				foreach ($resources as $id) {
					if (isset($tmp[$id])) {
						$matched[] = $id;
					}
				}
			}
		}
		
	    $match = $matched;
		$matched = array();
		$count = count($requested);
		$count_values = array_count_values($match);
		foreach ($count_values as $id => $value) {
		    if ($value >= $count) {
		        $matched[] = $id;
		    }
		    else {
		        $matched[] = 0;
		    }
		}
		return $matched;
	}

	/**
	 * This method returns preliminary results for each filter
	 *
	 * @param $ids
	 * @param array $request
	 * @param array $current
	 *
	 * @return array
	 */
	public function getSuggestions($ids, array $request, array $current = array()) {

        // Prepare cache key
        $built = $this->mse2->getFilters($ids, true);
        $possible_filters = array_flip(array_merge(array_keys($built), array_values($this->mse2->aliases)));
        foreach ($request as $k => $v) {
            if (!isset($possible_filters[$k])) {
                unset($request[$k]);
            }
        }
        sort($ids);
        ksort($request);
        sort($current);
        $key = array(
            'ids' => $ids,
            'request' => $request,
            'current' => $current,
            'config' => $this->config,
        );
        $cache_key = sha1(json_encode($key));
		
		if ($res = $this->modx->cacheManager->get($this->config['cache_prefix'] . 'sugg_' . $cache_key)) {
            return $res;
        }

        $current = array_flip($current);
        $filters = $this->mse2->getFilters($ids, false);
        $aliases = $this->mse2->aliases;
        $radio = $this->config['suggestionsRadio'];

        $suggestions = array();
	
        foreach ($filters as $table => $fields) {
            foreach ($fields as $field => $values) {
                $key = $alias = $table . $this->config['filter_delimeter'] . $field;
				
                if (!empty($aliases[$key])) {
                    $alias = $aliases[$key];
                }

                $tmp = current($built[$key]);
				
                if (empty($tmp['type']) || !in_array($tmp['type'], array('number', 'decimal'))) {
                    $tmp = $built[$key];
                    $values = array();
                    foreach ($tmp as $v) {
                        $values[] = $v['value'];
                    }
                } elseif (!empty($this->config['suggestionsSliders'])) {
                    $values = array_keys($values);
                } else {
                    continue;
                }

                foreach ($values as $value) {
                    $suggest = $request;

                    $added = 0;
                    if (isset($request[$alias])) {
                        // Types of suggestion can depend from method
                        if (!empty($radio) && in_array($key, $radio)) {
                            $suggest[$alias] = $value;
                        } elseif (in_array($tmp['type'], array('number', 'decimal'))) {
                            $tmp2 = explode($this->config['values_delimeter'], $request[$alias]);
                            if ($value <= $tmp2[0]) {
                                $tmp2[0] = $value;
                            } else {
                                $tmp2[1] = $value;
                            }
                            $suggest[$alias] = implode($this->config['values_delimeter'], $tmp2);
                        } else {
                            $tmp2 = explode($this->config['values_delimeter'], $request[$alias]);
                            if (!in_array($value, $tmp2)) {
                                $suggest[$alias] .= $this->config['values_delimeter'] . $value;
                                $added = 1;
                            }
                        }
                        $res = $this->mse2->Filter($ids, $suggest);
						
                        if ($added && !empty($res)) {

							$new_res = array();

                            foreach ($res as $k => $v) {
                                if (isset($current[$v])) {
                                    unset($res[$k]);
                                }
								if($v)
									$new_res[$k] = $v;
                            }

							$res = $new_res;

                            $count = count($res);
                            if ($count != 0) {
                                $count = $count;
                            }
                        }
                        else {
                            $count = count($res);
                        }
                    }
                    else {
                        $suggest[$alias] = $value;
                        $res = $this->mse2->Filter($ids, $suggest);
                        $count = count($res);
                    }

                    $suggestions[$alias][$value] = $count;
                }
            }
        }
        $this->modx->cacheManager->set($this->config['cache_prefix'] . 'sugg_' . $cache_key, $suggestions);

        return $suggestions;
		
	}
}
