<?php
/**
 * CommercePlant manages products/offers/orders, records transactions, and
 * deals with payment processors
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2013, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 *
 * This file is generously sponsored by Devin Palmer | www.devinpalmer.com
 *
 **/
class CommercePlant extends PlantBase {

	public function __construct($request_type,$request) {
		$this->request_type = 'commerce';
		$this->routing_table = array(
			// alphabetical for ease of reading
			// first value  = target method to call
			// second value = allowed request methods (string or array of strings)
			'additem'             => array('addItem','direct'),
			'additemvariants'     => array('addItemVariants','direct'),
			'addorder'            => array('addOrder','direct'),
			'addtocart'				 => array('addToCart',array('get','post','direct','api_public')),
			'addtransaction'      => array('addTransaction','direct'),
			'cancelorder'			 => array('cancelOrder','direct'),
			'deleteitem'          => array('deleteItem','direct'),
			'deleteitemvariant'   => array('deleteItemVariant','direct'),
			'deleteitemvariants'  => array('deleteItemVariants','direct'),
			'editcartquantity'	 => array('editCartQuantity',array('get','post','direct','api_public')),
			'editcartshipping'	 => array('editCartShipping',array('get','post','direct','api_public')),
			'edititem'            => array('editItem','direct'),
			'edititemvariant'   	 => array('editItemVariant','direct'),
			'editorder'           => array('editOrder','direct'),
			'edittransaction'     => array('editTransaction','direct'),
			'emailbuyersbyitem'	 => array('emailBuyersByItem','direct'),
			'emptycart'				 => array('emptyCart','direct'),
			'getanalytics'        => array('getAnalytics','direct'),
			'getcart'				 => array('getCart','direct'),
			'getitem'             => array('getItem','direct'),
			'getitemvariants'     => array('getItemVariants','direct'),
			'getitemsforuser'     => array('getItemsForUser','direct'),
			'getorder'            => array('getOrder','direct'),
			'getordersforuser'    => array('getOrdersForUser','direct'),
			'getordersbycustomer' => array('getOrdersByCustomer','direct'),
			'getordersbyitem'		 => array('getOrdersByItem','direct'),
			'getordertotals' 		 => array('getOrderTotals','direct'),
			'gettransaction'      => array('getTransaction','direct'),
			'finalizepayment'     => array('finalizeRedirectedPayment',array('get','post','direct')),
			'initiatecheckout'    => array('initiateCheckout',array('get','post','direct','api_public')),
			'sendorderreceipt'	 => array('sendOrderReceipt','direct')
		);
		$this->plantPrep($request_type,$request);
	}

	protected function addItem(
		$user_id,
		$name,
		$description='',
		$sku='',
		$price=0,
		$flexible_price=0,
		$available_units=-1,
		$digital_fulfillment=0,
		$physical_fulfillment=0,
		$physical_weight=0,
		$physical_width=0,
		$physical_height=0,
		$physical_depth=0,
		$variable_pricing=0,
		$fulfillment_asset=0,
		$descriptive_asset=0,
		$shipping=''
	   ) {
	   	if (!$fulfillment_asset) {
	   		$digital_fulfillment = false;
	   	}
		$result = $this->db->setData(
			'items',
			array(
				'user_id' => $user_id,
				'name' => $name,
				'description' => $description,
				'sku' => $sku,
				'price' => $price,
				'shipping' => json_encode($shipping),
				'flexible_price' => $flexible_price,
				'available_units' => $available_units,
				'digital_fulfillment' => $digital_fulfillment,
				'physical_fulfillment' => $physical_fulfillment,
				'physical_weight' => $physical_weight,
				'physical_width' => $physical_width,
				'physical_height' => $physical_height,
				'physical_depth' => $physical_depth,
				'variable_pricing' => $variable_pricing,
				'fulfillment_asset' => $fulfillment_asset,
				'descriptive_asset' => $descriptive_asset
			)
		);
		return $result;
	}

	protected function addItemVariants(
		$item_id,
		$variants
	) {

		$item_details = $this->getItem($item_id);
		if ($item_details) {
			$variant_ids = array();

			foreach ($variants as $attributes => $quantity) {

				$result = $this->db->setData(
					'item_variants',
					array(
						'item_id' 		=> $item_id,
						'user_id'		=> $item_details['user_id'],
						'attributes' 	=> $attributes,
						'quantity' 		=> $quantity,
					)
				);

				if (!$result) {
					return false;
				}

				$variant_ids[$attributes] = $result;
			}

			$this->updateItemQuantity($item_id);

			return $variant_ids;
		} else {
			return false;
		}
	}

	protected function getItem($id,$user_id=false,$with_variants=true) {
		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);
		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}
		$result = $this->db->getData(
			'items',
			'*',
			$condition
		);

		if ($result) {
			$item = $result[0];

			if ($with_variants) {
				$item['variants'] = $this->getItemVariants($id, $user_id);
			}

			$item['shipping'] = json_decode($item['shipping'],true);

			return $item;
		} else {
			return false;
		}
	}

	protected function getItemVariants($item_id, $exclude_empties = false, $user_id=false) {
		$condition = array(
			"item_id" => array(
				"condition" => "=",
				"value" => $item_id
			)
		);

		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}

		$result = $this->db->getData(
			'item_variants',
			'*',
			$condition
		);

		if ($result) {

			$variants = array(
				'attributes' => array(),
				'quantities' => array(),
			);

			$attributes = array();

			foreach ($result as $item) {

				if (!($item['quantity'] < 1 && $exclude_empties)) {
					$attribute_keys = explode('+', $item['attributes']);
					$name_pairs = array();

					foreach ($attribute_keys as $part) {

						list($key, $type) = explode('->', $part);

						if (!array_key_exists($key, $attributes)) {
							$attributes[$key] = array();
						}

						if (!array_key_exists($type, $attributes[$key])) {
							$attributes[$key][$type] = 0;
						}

						$attributes[$key][$type] += $item['quantity'];

						$name_pairs[] = "$key: $type";
					}

					$variants['quantities'][] = array(
						'id' => $item['id'],
						'key' => $item['attributes'],
						'formatted_name' => implode(", ", $name_pairs),
						'value' => $item['quantity']
					);
				}
			}

			foreach ($attributes as $key => $values) {
				$items = array();

				foreach ($values as $type => $quantity) {
					$items[] = array(
						'key' => $type,
						'value' => $quantity,
					);
				}

				$variants['attributes'][] = array(
					'key' => $key,
					'items' => $items
				);
			}

			return $variants;
		} else {
			return false;
		}
	}

	protected function editItem(
		$id,
		$name=false,
		$description=false,
		$sku=false,
		$price=false,
		$flexible_price=false,
		$available_units=false,
		$digital_fulfillment=false,
		$physical_fulfillment=false,
		$physical_weight=false,
		$physical_width=false,
		$physical_height=false,
		$physical_depth=false,
		$variable_pricing=false,
		$fulfillment_asset=false,
		$descriptive_asset=false,
		$user_id=false,
		$shipping=false
	   ) {
	   	if ($fulfillment_asset === 0) {
	   		$digital_fulfillment = 0;
	   	}
	   	if ($fulfillment_asset > 0) {
	   		$digital_fulfillment = 1;
	   	}
		$final_edits = array_filter(
			array(
				'name' => $name,
				'description' => $description,
				'sku' => $sku,
				'price' => $price,
				'shipping' => $shipping,
				'flexible_price' => $flexible_price,
				'available_units' => $available_units,
				'digital_fulfillment' => $digital_fulfillment,
				'physical_fulfillment' => $physical_fulfillment,
				'physical_weight' => $physical_weight,
				'physical_width' => $physical_width,
				'physical_height' => $physical_height,
				'physical_depth' => $physical_depth,
				'variable_pricing' => $variable_pricing,
				'fulfillment_asset' => $fulfillment_asset,
				'descriptive_asset' => $descriptive_asset
			),
			'CASHSystem::notExplicitFalse'
		);
		if (isset($final_edits['shipping'])) {
			$final_edits['shipping'] = json_encode($shipping);
		}
		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);
		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}
		$result = $this->db->setData(
			'items',
			$final_edits,
			$condition
		);
		return $result;
	}

	protected function editItemVariant($id, $quantity, $item_id, $user_id=false) {

		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id,
			)
		);

		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}

		$updates = array(
			'quantity' => $quantity
		);

		$result = $this->db->setData(
			'item_variants',
			$updates,
			$condition
		);

		if ($result) {
			$this->updateItemQuantity($item_id);
		}

		return $result;
	}

	protected function deleteItem($id,$user_id=false) {
		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);
		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}
		$result = $this->db->deleteData(
			'items',
			$condition
		);

		if (!$result) {
			return false;
		}
		$this->deleteItemVariants($id, $user_id);
		return $result;
	}

	protected function deleteItemVariant($id, $user_id=false) {

		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);

		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}

		$result = $this->db->deleteData(
			'item_variants',
			$condition
		);
		return $result;
	}

	protected function deleteItemVariants($item_id, $user_id=false) {

		$condition = array(
			"item_id" => array(
				"condition" => "=",
				"value" => $item_id
			)
		);

		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}

		$result = $this->db->deleteData(
			'item_variants',
			$condition
		);

		return $result;
	}

	protected function getItemsForUser($user_id,$with_variants=true) {
		$result = $this->db->getData(
			'items',
			'*',
			array(
				"user_id" => array(
					"condition" => "=",
					"value" => $user_id
				)
			)
		);

		if ($with_variants) {
			$length = count($result);

			for ($index = 0; $index < $length; $index++) {
				$result[$index]['variants'] = $this->getItemVariants($result[$index]['id'], false, $user_id);
				$result[$index]['shipping'] = json_decode($result[$index]['shipping'],true);
			}
		}

		return $result;
	}

	protected function emailBuyersByItem($user_id,$item_id,$connection_id,$subject,$message,$include_download=false) {
		$item_details = $this->getItem($item_id);

		if ($item_details['user_id'] == $user_id) {
			$merge_vars = null;
			$global_merge_vars = array(
				array(
					'name' => 'itemname',
					'content' => $item_details['name']
				),
				array(
					'name' => 'itemdescription',
					'content' => $item_details['description']
				)
			);

			$user_request = new CASHRequest(
				array(
					'cash_request_type' => 'people',
					'cash_action' => 'getuser',
					'user_id' => $user_id
				)
			);
			$user_details = $user_request->response['payload'];

			if ($user_details['display_name'] == 'Anonymous' || !$user_details['display_name']) {
				$user_details['display_name'] = $user_details['email_address'];
			}

			$recipients = array();
			$tmp_recipients = array();
			$all_orders = $this->getOrdersByItem($user_id,$item_id);
			foreach ($all_orders as $order) {
				$tmp_recipients[] = $order['customer_email'];
			}
			$tmp_recipients = array_unique($tmp_recipients);

			foreach ($tmp_recipients as $email) {
				$recipients[] = array(
					'email' => $email
				);
			}

			if (count($recipients)) {
				if (file_exists(CASH_PLATFORM_ROOT . '/lib/markdown/markdown.php')) {
					include_once(CASH_PLATFORM_ROOT . '/lib/markdown/markdown.php');
				}
				$html_message = Markdown($message);

				if ($include_download) {
					$asset_request = new CASHRequest(
						array(
							'cash_request_type' => 'asset',
							'cash_action' => 'getasset',
							'id' => $item_details['fulfillment_asset']
						)
					);
					if ($asset_request->response['payload']) {
						$unlock_suffix = 1;
						$all_assets = array();
						if ($asset_request->response['payload']['type'] == 'file') {
							$message .= "\n\n" . 'Download *|ITEMNAME|* at '.CASH_PUBLIC_URL.'/download/?code=*|UNLOCKCODE1|*';
							$html_message .= "\n\n" . '<p><b><a href="'.CASH_PUBLIC_URL.'/download/?code=*|UNLOCKCODE1|*">Download *|ITEMNAME|*</a></b></p>';
							$all_assets[] = array(
								'id' => $item_details['fulfillment_asset'],
								'name' => $asset_request->response['payload']['title']
							);
						} else {
							$message .= "\n\n" . '*|ITEMNAME|*:' . "\n\n";
							$html_message .= "\n\n" . '<p><b>*|ITEMNAME|*:</b></p>';
							$fulfillment_request = new CASHRequest(
								array(
									'cash_request_type' => 'asset',
									'cash_action' => 'getfulfillmentassets',
									'asset_details' => $asset_request->response['payload']
								)
							);
							if ($fulfillment_request->response['payload']) {
								foreach ($fulfillment_request->response['payload'] as $asset) {
									$all_assets[] = array(
										'id' => $asset['id'],
										'name' => $asset['title']
									);
									$message .= "\n\n" . 'Download *|ASSETNAME'.$unlock_suffix.'|* at '.CASH_PUBLIC_URL.'/download/?code=*|UNLOCKCODE'.$unlock_suffix.'|*';
									$html_message .= "\n\n" . '<p><b><a href="'.CASH_PUBLIC_URL.'/download/?code=*|UNLOCKCODE'.$unlock_suffix.'|*">Download *|ASSETNAME'.$unlock_suffix.'|*</a></b></p>';
									$unlock_suffix++;
								}
							}
						}
						$merge_vars = array();
						$all_vars = array();
						$unlock_suffix = 1;
						foreach ($recipients as $recipient) {
							foreach ($all_assets as $asset) {
								$addcode_request = new CASHRequest(
									array(
										'cash_request_type' => 'asset',
										'cash_action' => 'addlockcode',
										'asset_id' => $asset['id']
									)
								);
								$all_vars[] = array(
									'name' => 'assetname'.$unlock_suffix,
									'content' => $asset['name']
								);
								$all_vars[] = array(
									'name' => 'unlockcode'.$unlock_suffix,
									'content' => $addcode_request->response['payload']
								);
								$unlock_suffix++;
							}
							if ($addcode_request->response['payload']) {
								$merge_vars[] = array(
									'rcpt' => $recipient['email'],
									'vars' => $all_vars
								);
							}
							$all_vars = array();
							$unlock_suffix = 1;
						}
					}
				}

				$mandrill = new MandrillSeed($user_id,$connection_id);
				$result = $mandrill->send(
					$subject,
					$message,
					$html_message,
					$user_details['email_address'],
					$user_details['display_name'],
					$recipients,
					null,
					$global_merge_vars,
					$merge_vars
				);
				return $result;
			}
		} else {
			return false;
		}
	}

	protected function addToCart($item_id,$item_variant=false,$price=false,$session_id=false) {
		$r = new CASHRequest();
		$r->startSession(false,$session_id);

		$cart = $r->sessionGet('cart');
		if (!$cart) {
			$cart = array(
				'shipto' => ''
			);
		}
		$qty = 1;
		if (isset($cart[$item_id.$item_variant])) {
			$qty = $cart[$item_id.$item_variant]['qty'] + 1;
		}
		$cart[$item_id.$item_variant] = array(
			'id' 		 	 => $item_id,
			'variant' 	 => $item_variant,
			'price' 		 => $price,
			'qty'		 	 => $qty
		);

		$r->sessionSet('cart', $cart);
		return $cart;
	}

	protected function editCartQuantity($item_id,$qty,$item_variant='',$session_id=false) {
		$r = new CASHRequest();
		$r->startSession(false,$session_id);

		$cart = $r->sessionGet('cart');
		if (!$cart) {
			return false;
		}

		if (!isset($cart[$item_id.$item_variant])) {
			return false;
		} else {
			if ($qty == 0) {
				unset($cart[$item_id.$item_variant]);
			} else {
				$cart[$item_id.$item_variant]['qty'] = $qty;
			}
			$r->sessionSet('cart', $cart);
			return $cart;
		}
	}

	protected function editCartShipping($region='r1',$session_id=false) {
		$r = new CASHRequest();
		$r->startSession(false,$session_id);

		$cart = $r->sessionGet('cart');
		if (!$cart) {
			return false;
		}

		$cart['shipto'] = $region;
		$r->sessionSet('cart', $cart);
		return $cart;
	}

	protected function emptyCart($session_id=false) {
		$r = new CASHRequest();
		$r->startSession(false,$session_id);
		$r->sessionClear('cart');
	}

	protected function getCart($session_id=false) {
		$r = new CASHRequest();
		$r->startSession(false,$session_id);
		return $r->sessionGet('cart');
	}

	protected function addOrder(
		$user_id,
		$order_contents,
		$transaction_id=-1,
		$physical=0,
		$digital=0,
		$cash_session_id='',
		$element_id=0,
		$customer_user_id=0,
		$fulfilled=0,
		$canceled=0,
		$notes='',
		$country_code='',
		$currency='USD'
	) {
		if (is_array($order_contents)) {
			/*
				basically we store as JSON to prevent loss of order history
				in the event an item changes or is deleted. we want accurate
				history so folks don't get all crazy bananas about teh $$s
			*/
			$final_order_contents = json_encode($order_contents);
			$result = $this->db->setData(
				'orders',
				array(
					'user_id' => $user_id,
					'customer_user_id' => $customer_user_id,
					'transaction_id' => $transaction_id,
					'order_contents' => $final_order_contents,
					'fulfilled' => $fulfilled,
					'canceled' => $canceled,
					'physical' => $physical,
					'digital' => $digital,
					'notes' => $notes,
					'country_code' => $country_code,
					'currency' => $currency,
					'element_id' => $element_id,
					'cash_session_id' => $cash_session_id
				)
			);
			return $result;
		} else {
			return false;
		}
	}

	protected function getOrder($id,$deep=false,$user_id=false) {
		if ($deep) {
			$result = $this->db->getData(
				'CommercePlant_getOrder_deep',
				false,
				array(
					"id" => array(
						"condition" => "=",
						"value" => $id
					)
				)
			);

			if ($result) {
				if ($user_id) {
					if ($result[0]['user_id'] != $user_id) {
						return false;
					}
				}
				$result[0]['order_totals'] = $this->getOrderTotals($result[0]['order_contents']);
				$transaction_data = $this->parseTransactionData($result[0]['data_returned']);

				if (is_array($transaction_data)) {
					$result[0] = array_merge($result[0],$transaction_data);
				}

				$order['order_description'] = $result[0]['order_totals']['description'];

				$user_request = new CASHRequest(
					array(
						'cash_request_type' => 'people',
						'cash_action' => 'getuser',
						'user_id' => $result[0]['customer_user_id']
					)
				);
				$result[0]['customer_details'] = $user_request->response['payload'];
			}
		} else {
			$condition = array(
				"id" => array(
					"condition" => "=",
					"value" => $id
				)
			);
			if ($user_id) {
				$condition['user_id'] = array(
					"condition" => "=",
					"value" => $user_id
				);
			}
			$result = $this->db->getData(
				'orders',
				'*',
				$condition
			);
		}

		if ($result) {
			return $result[0];
		} else {
			return false;
		}
	}

	protected function editOrder(
		$id,
		$fulfilled=false,
		$canceled=false,
		$notes=false,
		$country_code=false,
		$customer_user_id=false,
		$order_contents=false,
		$transaction_id=false,
		$physical=false,
		$digital=false,
		$user_id=false
	) {
		if ($order_contents) {
			$order_contents = json_encode($order_contents);
		}
		$final_edits = array_filter(
			array(
				'transaction_id' => $transaction_id,
				'order_contents' => $order_contents,
				'fulfilled' => $fulfilled,
				'canceled' => $canceled,
				'physical' => $physical,
				'digital' => $digital,
				'notes' => $notes,
				'country_code' => $country_code,
				'customer_user_id' => $customer_user_id
			),
			'CASHSystem::notExplicitFalse'
		);
		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);
		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}
		$result = $this->db->setData(
			'orders',
			$final_edits,
			$condition
		);
		return $result;
	}

	protected function parseTransactionData($data) {
		return json_decode($data, true);
	}

	protected function getOrdersForUser($user_id,$include_abandoned=false,$max_returned=false,$since_date=0,$unfulfilled_only=0,$deep=false,$skip=0) {
		if ($max_returned) {
			$limit = $skip . ', ' . $max_returned;
		} else {
			$limit = false;
		}
		if ($deep) {
			$result = $this->db->getData(
				'CommercePlant_getOrders_deep',
				false,
				array(
					"user_id" => array(
						"condition" => "=",
						"value" => $user_id
					),
					"unfulfilled_only" => array(
						"condition" => "=",
						"value" => $unfulfilled_only
					),
					"since_date" => array(
						"condition" => ">",
						"value" => $since_date
					)
				),
				$limit
			);
			if ($result) {
				// loop through and parse all transactions
				if (is_array($result)) {
					foreach ($result as &$order) {
						$transaction_data = $this->parseTransactionData($order['connection_type'],$order['data_sent']);
						if (is_array($transaction_data)) {
							$order = array_merge($order,$transaction_data);
						}
						$order_totals = $this->getOrderTotals($order['order_contents']);

						$order['order_description'] = $order_totals['description'];
					}
				}
			}
		} else {
			$conditions = array(
				"user_id" => array(
					"condition" => "=",
					"value" => $user_id
				),
				"creation_date" => array(
					"condition" => ">",
					"value" => $since_date
				)
			);
			if ($unfulfilled_only) {
				$conditions['fulfilled'] = array(
					"condition" => "=",
					"value" => 0
				);
			}
			if (!$include_abandoned) {
				$conditions['modification_date'] = array(
					"condition" => ">",
					"value" => 0
				);
			}
			$result = $this->db->getData(
				'orders',
				'*',
				$conditions,
				$limit,
				'id DESC'
			);
		}
		return $result;
	}

	protected function getOrdersByCustomer($user_id,$customer_email) {
		$user_request = new CASHRequest(
			array(
				'cash_request_type' => 'people',
				'cash_action' => 'getuseridforaddress',
				'address' => $customer_email
			)
		);
		$customer_id = $user_request->response['payload'];

		$result = $this->db->getData(
			'orders',
			'*',
			array(
				"user_id" => array(
					"condition" => "=",
					"value" => $user_id
				),
				"customer_user_id" => array(
					"condition" => "=",
					"value" => $customer_id
				),
				"modification_date" => array(
					"condition" => ">",
					"value" => 0
				)
			)
		);
		return $result;
	}

	protected function getOrdersByItem($user_id,$item_id,$max_returned=false,$skip=0) {
		if ($max_returned) {
			$limit = $skip . ', ' . $max_returned;
		} else {
			$limit = false;
		}
		$result = $this->db->getData(
			'CommercePlant_getOrders_deep',
			false,
			array(
				"user_id" => array(
					"condition" => "=",
					"value" => $user_id
				),
				"contains_item" => array(
					"condition" => "=",
					"value" => '%"id":"' . $item_id . '"%'
				)
			),
			$limit
		);
		if ($result) {
			// loop through and parse all transactions
			if (is_array($result)) {
				foreach ($result as &$order) {
					$transaction_data = $this->parseTransactionData($order['connection_type'],$order['data_sent']);
					if (is_array($transaction_data)) {
						$order = array_merge($order,$transaction_data);
					}
					$order_totals = $this->getOrderTotals($order['order_contents']);
					$order['order_description'] = $order_totals['description'];
				}
			}
		}
		return $result;
	}

	protected function addTransaction(
		$user_id,
		$connection_id,
		$connection_type,
		$service_timestamp='',
		$service_transaction_id='',
		$data_sent='',
		$data_returned='',
		$successful=-1,
		$gross_price=0,
		$service_fee=0,
		$status='abandoned',
		$currency='USD'
	) {
		$result = $this->db->setData(
			'transactions',
			array(
				'user_id' => $user_id,
				'connection_id' => $connection_id,
				'connection_type' => $connection_type,
				'service_timestamp' => $service_timestamp,
				'service_transaction_id' => $service_transaction_id,
				'data_sent' => $data_sent,
				'data_returned' => $data_returned,
				'successful' => $successful,
				'gross_price' => $gross_price,
				'service_fee' => $service_fee,
				'currency' => $currency,
				'status' => $status
			)
		);
		return $result;
	}

	protected function getTransaction($id,$user_id=false) {
		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);
		if ($user_id) {
			$condition['user_id'] = array(
				"condition" => "=",
				"value" => $user_id
			);
		}
		$result = $this->db->getData(
			'transactions',
			'*',
			$condition
		);

		if ($result) {
			return $result[0];
		} else {
			return false;
		}
	}

	protected function editTransaction(
		$id,
		$service_timestamp=false,
		$service_transaction_id=false,
		$data_sent=false,
		$data_returned=false,
		$successful=false,
		$gross_price=false,
		$service_fee=false,
		$status=false
	) {
		$final_edits = array_filter(
			array(
				'service_timestamp' => $service_timestamp,
				'service_transaction_id' => $service_transaction_id,
				'data_sent' => $data_sent,
				'data_returned' => $data_returned,
				'successful' => $successful,
				'gross_price' => $gross_price,
				'service_fee' => $service_fee,
				'status' => $status
			),
			'CASHSystem::notExplicitFalse'
		);

		$result = $this->db->setData(
			'transactions',
			$final_edits,
			array(
				'id' => array(
					'condition' => '=',
					'value' => $id
				)
			)
		);
		return $result;
	}

	protected function updateItemQuantity(
		$id
	) {

		$result = $this->db->getData(
			'CommercePlant_getTotalItemVariantsQuantity',
			false,
			array(
				"item_id" => array(
					"condition" => "=",
					"value" => $id
				)
			)
		);

		if (!$result) {
			return false;
		}

		$updates = array(
			'available_units' => $result[0]['total_quantity']
		);

		$condition = array(
			"id" => array(
				"condition" => "=",
				"value" => $id
			)
		);

		$result = $this->db->setData(
			'items',
			$updates,
			$condition
		);

		return $result;
	}

	protected function initiateCheckout($user_id=false,$connection_id=false,$order_contents=false,$item_id=false,$element_id=false,$total_price=false,$url_only=false,$finalize_url=false,$session_id=false) {

		//TODO: store last seen top URL
		//      or maybe make the API accept GET params? does it already? who can know?

		if (!$item_id && !$element_id) {
			return false;
		} else {
			$is_physical = 0;
			$is_digital = 0;
			if (!$order_contents) {
				$order_contents = array();
			}
			if ($item_id) {
				// old style...we'll be refactoring this junk
				$item_details = $this->getItem($item_id);
				$item_details['qty'] = 1; // hard-coded to support old one-item-only stuff
				$order_contents[] = $item_details;
				if ($total_price !== false && $total_price >= $item_details['price']) {
					$price_addition = $total_price - $item_details['price'];
				} elseif ($total_price === false) {
					$price_addition = 0;
				} else {
					return false;
				}
				if ($item_details['physical_fulfillment']) {
					$is_physical = 1;
				}
				if ($item_details['digital_fulfillment']) {
					$is_digital = 1;
				}
			} else {
				$element_request = new CASHRequest(
					array(
						'cash_request_type' => 'element',
						'cash_action' => 'getelement',
						'id' => $element_id
					)
				);
				$user_id = $element_request->response['payload']['user_id'];
				$settings_request = new CASHRequest(
					array(
						'cash_request_type' => 'system',
						'cash_action' => 'getsettings',
						'type' => 'payment_defaults',
						'user_id' => $user_id
					)
				);
				if (is_array($settings_request->response['payload'])) {
					$pp_default = $settings_request->response['payload']['pp_default'];
					$pp_micro = $settings_request->response['payload']['pp_micro'];
				} else {
					return false; // no default PP shit set
				}
				$cart = $this->getCart($session_id);
				$shipto = $cart['shipto'];
				unset($cart['shipto']);
				if ($shipto != 'r1' && $shipto != 'r2') {
					$shipto = 'r1';
				}
				$subtotal = 0;
				$shipping = 0;
				foreach ($cart as $key => &$i) {
					$item_details = $this->getItem($i['id'],false,false);
					$variants = $this->getItemVariants($item_id);
					$item_details['qty'] = $i['qty'];
					$item_details['price'] = max($i['price'],$item_details['price']);
					$subtotal += $item_details['price']*$i['qty'];
					$item_details['variant'] = $i['variant'];

					if ($item_details['physical_fulfillment']) {
						$is_physical = 1;
					}
					if ($item_details['digital_fulfillment']) {
						$is_digital = 1;
					}
					if ($item_details['shipping'] && $shipto) {
						if (isset($item_details['shipping']['r1-1'])) {
							$shipping += $item_details['shipping'][$shipto.'-1+']*($i['qty']-1)+$item_details['shipping'][$shipto.'-1'];
						}
					}
					if ($variants) {
						foreach ($variants['quantities'] as $q) {
							if ($q['key'] == $item_details['variant']) {
								$item_details['variant_name'] = $q['formatted_name'];
								break;
							}
						}
					}
					$order_contents[] = $item_details;
				}
				$price_addition = $shipping;

				//TODO: this connection stuff is hard-coded for paypal, but does the default/micro switch well
				if (($subtotal+$shipping < 12) && $pp_micro) {
					$connection_id = $pp_micro;
				} else {
					$connection_id = $pp_default;
				}
			}

			$currency = $this->getCurrencyForUser($user_id);

			$transaction_id = $this->addTransaction(
				$user_id,
				$connection_id,
				$this->getConnectionType($connection_id),
				'',
				'',
				'',
				'',
				-1,
				0,
				0,
				'abandoned',
				$currency
			);
			$order_id = $this->addOrder(
				$user_id,
				$order_contents,
				$transaction_id,
				$is_physical,
				$is_digital,
				$this->getSessionID(),
				$element_id,
				0,
				0,
				0,
				'',
				'',
				$currency
			);
			if ($order_id) {
				$success = $this->initiatePaymentRedirect($order_id,$element_id,$price_addition,$url_only,$finalize_url,$session_id);
				return $success;
			} else {
				return false;
			}
		}
	}

	protected function getOrderTotals($order_contents) {
		$contents = json_decode($order_contents,true);
		$return_array = array(
			'price' => 0,
			'description' => ''
		);
		foreach($contents as $item) {
			if (!isset($item['qty'])) {
				$item['qty'] = 1;
			}
			$return_array['price'] += $item['price']*$item['qty'];
			if (isset($item['qty'])) {
				$return_array['description'] .= $item['qty'] . 'x ';
			}

			// checking physical fulfillment

			$return_array['description'] .= $item['name'];
			if (isset($item['variant'])) {
				if ($item['variant']) {

					preg_match_all("/([a-z]+)->/", $item['variant'], $key_parts);

					$variant_keys = $key_parts[1];
					$variant_values = preg_split("/([a-z]+)->/", $item['variant'], 0, PREG_SPLIT_NO_EMPTY);
					$count = count($variant_keys);

					$variant_descriptions = array();

					for($index = 0; $index < $count; $index++) {
						$key = $variant_keys[$index];
						$value = trim(str_replace('+', ' ', $variant_values[$index]));
						$variant_descriptions[] = "$key: $value";
					}

					$return_array['description'] .= ' (' . implode(', ', $variant_descriptions) . ')';
				}
			}
			$return_array['description'] .= ",  \n";
		}


		$return_array['description'] = rtrim($return_array['description']," ,\n");
		return $return_array;
	}

	protected function getCurrencyForUser($user_id) {
		$currency_request = new CASHRequest(
			array(
				'cash_request_type' => 'system',
				'cash_action' => 'getsettings',
				'type' => 'use_currency',
				'user_id' => $user_id
			)
		);
		if ($currency_request->response['payload']) {
			$currency = $currency_request->response['payload'];
		} else {
			$currency = 'USD';
		}
		return $currency;
	}

	/**
	 * $this->getOrderProperties
	 *
	 * Literally just returning the first index of this item, for legibility
	 * @param string $order_contents
	 * @return array or bool
	 */
	protected function getOrderProperties($order_contents) {
		$contents = json_decode($order_contents, true);

		if (!empty($contents[0])) {
			return $contents[0];
		} else {
			return false;
		}
	}

	protected function initiatePaymentRedirect($order_id,$element_id=false,$price_addition=0,$url_only=false,$finalize_url=false,$session_id=false) {

		// set values
		$order_details = $this->getOrder($order_id);
		$order_properties = $this->getOrderProperties($order_details['order_contents']);

		$transaction_details = $this->getTransaction($order_details['transaction_id']);

		$order_totals = $this->getOrderTotals($order_details['order_contents']);
		$connection_type = $this->getConnectionType($transaction_details['connection_id']);

		// get connection type settings so we can extract Seed classname
		$connection_settings = CASHSystem::getConnectionTypeSettings($connection_type);
		$seed_class = $connection_settings['seed'];

		$payment_amount = $order_totals['price'] + $price_addition;
		$currency = $this->getCurrencyForUser($order_details['user_id']);
		$shipping_required = false;
		$note_allowed = false;

		if ($finalize_url) {
			$r = new CASHRequest();
			$r->startSession(false,$session_id);
			$r->sessionSet('payment_finalize_url',$finalize_url);
		}

		if (($order_totals['price'] + $price_addition) < 0.35) {
			// basically a zero dollar transaction. hard-coding a 35¢ minimum for now
			// we can add a system minimum later, or a per-connection minimum, etc...
			return 'force_success';
		}

		// we're going to switch seeds by $connection_type, so check to make sure this class even exists
		if (!class_exists($seed_class)) {
			$this->setErrorMessage("Couldn't find payment type $connection_type.");
			return false;
		}

		// call the payment seed class
		$payment_seed = new $seed_class($order_details['user_id'],$transaction_details['connection_id']);

		if (!$finalize_url) {
			$finalize_url = CASHSystem::getCurrentURL();
		}

		// build a return URL for the payment system to redirect to once payment is ready to be finalized
		$return_url = $finalize_url . '?cash_request_type=commerce&cash_action=finalizepayment&order_id=' . $order_id . '&creation_date=' . $order_details['creation_date'];
		if ($element_id) {
			$return_url .= '&element_id=' . $element_id;
		}

		if (!empty($_POST['email'])) {
			$return_url .= '&email='.$_POST['email'];
		}

		if (!empty($_POST['connection_id'])) {
			$return_url .= '&connection_id='.$_POST['connection_id'];
		}

		if (!empty($_POST['connection_id'])) {
			$return_url .= '&connection_id='.$_POST['connection_id'];
		}

		if (!empty($_POST['stripeToken'])) {
			$return_url .= '&stripeToken='.$_POST['stripeToken'];
		}


		// collect shipping information on payment service, if possible

		if ($order_properties['physical_fulfillment'] == 1) {
			$shipping_required = true;
			$note_allowed = true;
		}

		// create checkout object with URL redirect
		$payment_details = $payment_seed->setCheckout(
				$payment_amount,							# payment amount
				'order-' . $order_id,						# order id
				$order_totals['description'],				# order name
				$return_url,								# return URL
				$return_url,								# cancel URL (the same in our case)
				$shipping_required,							# shipping info required (boolean)
				$note_allowed,								# allow an order note (boolean)
				$currency,									# payment currency
				'sale',										# transaction type (e.g. 'Sale', 'Order', or 'Authorization')
				false,										# invoice (boolean)
				$price_addition								# price additions (like shipping, but could be taxes in future as well)
		);



		$this->editTransaction(
			$order_details['transaction_id'], 		// order id
			false, 									// service timestamp
			false,										// service transaction id
			json_encode($payment_details['data_sent']),	// data sent
			false,			// data received
			false,										// successful (boolean 0/1)
			false,										// gross price
			false,										// service fee
			false									// transaction status
		);

		if (!$url_only) {
			$redirect = CASHSystem::redirectToUrl($payment_details['redirect_url']);
			// the return will only happen if headers have already been sent
			// if they haven't redirectToUrl() will handle it and call exit
			return $redirect;
		} else {
			return $payment_details['redirect_url'];
		}

		return false;
	}


	protected function finalizeRedirectedPayment($order_id,$creation_date,$direct_post_details=false,$session_id=false) {

		$order_details = $this->getOrder($order_id);
		$transaction_details = $this->getTransaction($order_details['transaction_id']);
		$connection_type = $this->getConnectionType($transaction_details['connection_id']);

		// get connection type settings so we can extract Seed classname
		$connection_settings = CASHSystem::getConnectionTypeSettings($connection_type);
		$seed_class = $connection_settings['seed'];

		$r = new CASHRequest();
		$r->startSession(false,$session_id);
		$finalize_url = $r->sessionGet('payment_finalize_url');
		if ($finalize_url) {
			$r->sessionClear('payment_finalize_url');
		}

		// we're going to switch seeds by $connection_type, so check to make sure this class even exists
		if (!class_exists($seed_class)) {
			$this->setErrorMessage("Couldn't find payment type $connection_type.");
			return false;
		}

		// call the payment seed class
		$payment_seed = new $seed_class($order_details['user_id'],$transaction_details['connection_id']);



		// if this was approved by the user, we need to compare some values to make sure everything matches up
		if ($payment_details = $payment_seed->getCheckout()) {

			$order_totals = $this->getOrderTotals($order_details['order_contents']);

			// okay, we've got the matching totals, so let's get the $user_id, y'all
			if ($payment_details['total'] >= $order_totals['price']) {

				if ($user_id = $this->getOrCreateUser($payment_details['payer'])) {

					// marking order fulfillment for digital only, physical quantities, all that fun stuff
					$is_fulfilled = $this->processProducts($order_details);

					// takin' care of business
					$this->editOrder(
						$order_id, 		// order id
						$is_fulfilled,	// fulfilled status
						0,				// cancelled (boolean 0/1)
						false,			// notes
						$payment_details['payer']['country_code'],	// country code
						$user_id		// user id
					);

					$this->editTransaction(
						$order_details['transaction_id'], 		// order id
						$payment_details['timestamp'], 			// service timestamp
						$payment_details['transaction_id'],		// service transaction id
						false,									// data sent
						$payment_details['order_details'],			// data received
						1,										// successful (boolean 0/1)
						$payment_details['total'],				// gross price
						$payment_details['transaction_fee']['value'],	// service fee
						'complete'								// transaction status
					);

					// empty the cart at this point
					$this->emptyCart($session_id);
//					error_log( print_r($payment_details['payer'], true)  );
					// TODO: add code to order metadata so we can track opens, etc
					$order_details['customer_details']['email_address'] = $payment_details['payer']['email'];

					$order_details['gross_price'] = $payment_details['total'];
					$this->sendOrderReceipt(false,$order_details,$finalize_url);
					return $order_details['id'];

				} else {
					$this->setErrorMessage("Couldn't find your account.");
					return false;
				}

			} else {

				$this->setErrorMessage("The order total and payment total don't match.");
				return false;
			}

		} else {

			$this->setErrorMessage("There's no record of this payment.");
			return false;
		}

	}


	/**
	 * Find a user's id by their email, or create one
	 * @param array $payer
	 * @return int
     */

	protected function getOrCreateUser(array $payer) {

		// let's try to find this user id via email
		$user_request = new CASHRequest(
			array('cash_request_type' => 'people',
				'cash_action' => 'getuseridforaddress',
				'address' => $payer['email'])
		);

		$user_id = $user_request->response['payload'];

		// no dice, so let's make them feel welcome and create an account
		if (!$user_id) {

			$user_request = new CASHRequest(
				array('cash_request_type' => 'system',
					'cash_action' => 'addlogin',
					'address' => $payer['email'],
					'password' => time(),
					'username' => preg_replace('/\s+/', '', $payer['first_name'] . $payer['last_name']),
					'is_admin' => 0,
					'display_name' => $payer['first_name'] . ' ' . $payer['last_name'],
					'first_name' => $payer['first_name'],
					'last_name' => $payer['last_name'],
					'address_country' => $payer['country_code'])
			);

			$user_id = $user_request->response['payload'];
		}

		if (!$user_id) {
			//TODO: uh oh, something went wrong while trying to create a user, maybe?
			$this->setErrorMessage("Something went wrong while trying to create a new user.");
			return false;
		} else {
			return $user_id;
		}
	}

	/**
	 * Mostly dealing with physical quantities, and order fulfillment status for digital
	 * @param array $order_details
	 * @return $is_fulfilled int
     */
	protected function processProducts(array $order_details) {

		// deal with physical quantities
		if ($order_details['physical'] == 1) {
			$order_items = json_decode($order_details['order_contents'],true);
			if (is_array($order_items)) {
				foreach ($order_items as $i) {
					if ($i['available_units'] > 0 && $i['physical_fulfillment'] == 1) {
						$item = $this->getItem($i['id']);
						if ($i['variant']) {
							$variant_id = 0;
							$variant_qty = 0;
							if ($item['variants']) {
								foreach ($item['variants']['quantities'] as $q) {
									if ($q['key'] == $i['variant']) {
										$variant_id = $q['id'];
										$variant_qty = $q['value'];
										break;
									}
								}
								if ($variant_id) {
									$this->editItemVariant($variant_id, max($variant_qty-$i['qty'],0), $i['id']);
								}
							}
						} else {
							$available_units =
								$this->editItem(
									$i['id'],
									false,
									false,
									false,
									false,
									false,
									max($item['available_units'] - $i['qty'],0)
								);
						}
					}
				}
			}
		}

		// record all the details
		if ($order_details['digital'] == 1 && $order_details['physical'] == 0) {
			// if the order is 100% digital just mark it as fulfilled
			$is_fulfilled = 1;
		} else {
			// there's something physical. sorry dude. gotta deal with it still.
			$is_fulfilled = 0;
		}

		return $is_fulfilled;

	}

	protected function sendOrderReceipt($id=false,$order_details=false,$finalize_url=false) {
		if (!$id && !$order_details) {
			return false;
		}
		if (!$order_details) {
			$order_details = $this->getOrder($id,true);
		}

		$order_totals = $this->getOrderTotals($order_details['order_contents']);
		try {
			$personalized_message = '';
			if ($order_details['element_id']) {
				$element_request = new CASHRequest(
					array(
						'cash_request_type' => 'element',
						'cash_action' => 'getelement',
						'id' => $order_details['element_id']
					)
				);

				if ($element_request->response['payload']) {
					if (isset($element_request->response['payload']['options']['message_email'])) {
						if ($element_request->response['payload']['options']['message_email']) {
							$personalized_message = $element_request->response['payload']['options']['message_email'] . "\n\n";
						}
					}
				}
			}

			if ($order_details['digital']) {
				$addcode_request = new CASHRequest(
					array(
						'cash_request_type' => 'element',
						'cash_action' => 'addlockcode',
						'element_id' => $order_details['element_id']
					)
				);

				if (!$finalize_url) {
					$finalize_url = CASHSystem::getCurrentURL();
				}

				return CASHSystem::sendEmail(
					'Thank you for your order',
					$order_details['user_id'],
					$order_details['customer_details']['email_address'],
					$personalized_message . "Your order is complete. Here are some details:\n\n**Order #" . $order_details['id'] . "**  \n"
					. $order_totals['description'] . "  \n Total: " . CASHSystem::getCurrencySymbol($order_details['currency']) . number_format($order_details['gross_price'],2) . "\n\n"
					. "\n\n" . '[View your receipt and any downloads](' . $finalize_url . '?cash_request_type=element&cash_action=redeemcode&code=' . $addcode_request->response['payload']
					. '&element_id=' . $order_details['element_id'] . '&email=' . urlencode($order_details['customer_details']['email_address']) . '&order_id=' . $order_details['id'] . ')',
					'Thank you.'
				);
			} else {
				return CASHSystem::sendEmail(
					'Thank you for your order',
					$order_details['user_id'],
					$order_details['customer_details']['email_address'],
					$personalized_message . "Your order is complete. Here are some details:\n\n**Order #" . $order_details['id'] . "**  \n"
					. $order_totals['description'] . "  \n Total: " . CASHSystem::getCurrencySymbol($order_details['currency']) . number_format($order_details['gross_price'],2) . "\n\n",
					'Thank you.'
				);
			}
		} catch (Exception $e) {
			// TODO: handle the case where an email can't be sent. maybe display the download
			//       code on-screen? that plus storing it with the order is probably enough
			return false;
		}
	}

	protected function cancelOrder($order_id,$user_id=false) {

		$order_details = $this->getOrder($order_id,true);
		//error_log( "details + " . print_r( $order_details, true) );
		$transaction_details = $this->getTransaction($order_id); //$order_details['transaction_id']

		$connection_type = $this->getConnectionType($transaction_details['connection_id']);

		// get connection type settings so we can extract Seed classname
		$connection_settings = CASHSystem::getConnectionTypeSettings($connection_type);
		$seed_class = $connection_settings['seed'];

		// we're going to switch seeds by $connection_type, so check to make sure this class even exists
		if (!class_exists($seed_class)) {
			$this->setErrorMessage("Couldn't find payment type $connection_type.");
			return false;
		}

		// if we send a user id, make sure that user matches the order
		if ($user_id) {
			if ($user_id != $order_details['user_id']) {
				return false;
			}
		}

		// call the payment seed class
		$payment_seed = new $seed_class($order_details['user_id'],$transaction_details['connection_id']);

		$refund_details = $payment_seed->doRefund(
			$order_details['sale_id'],
			$order_details['total']
		);
//		error_log( print_r($refund_details, true) );

		// check initial refund success
		if (!$refund_details) {
			error_log("refund returning false");
			return false;
		} else {
			error_log("refund returning true");
			// make sure the refund went through fully, return false if not
			$this->editOrder(
				$order_id,
				false,
				1,
				"Cancelled " . date("F j, Y, g:i a T") . "\n\n" . $order_details['notes']
			);

			$this->editTransaction(
				$order_id,
				false,
				false,
				false,
				false,
				false,
				false,
				false,
				'refunded'
			);

			return true;

			// NOTE:
			// we aren't restocking physical goods for a few reasons:
			// 1. cancellations should be less common than sales
			// 2. lack of inventory is a common reason to cancel, restocking makes it worse
			// 3. manually re-adding stock isn't hard
			// 4. if an order is a return of damaged goods, you won't restocking
			// 5. fuck it
		}


// entre vous



	}

	/**
	 * Pulls analytics queries in a few different formats
	 *
	 * @return array
	 */protected function getAnalytics($analtyics_type,$user_id,$date_low=false,$date_high=false) {
		//
		// left a commented-out switch so we can easily add more cases...
		//
		//switch (strtolower($analtyics_type)) {
		//	case 'transactions':
				if (!$date_low) $date_low = 201243600;
				if (!$date_high) $date_high = time();
				$result = $this->db->getData(
					'CommercePlant_getAnalytics_transactions',
					false,
					array(
						"user_id" => array(
							"condition" => "=",
							"value" => $user_id
						),
						"date_low" => array(
							"condition" => "=",
							"value" => $date_low
						),
						"date_high" => array(
							"condition" => "=",
							"value" => $date_high
						)
					)
				);
				if ($result) {
					return $result[0];
				} else {
					return $result;
				}
		//		break;
		//}
	}
	public function setErrorMessage($message) {
		error_log($message);
	}



} // END class
?>
