<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

#include <rules/DataPacket.h>


use pocketmine\network\mcpe\NetworkSession;

class LoginPacket extends DataPacket{
	const NETWORK_ID = ProtocolInfo::LOGIN_PACKET;

	const MOJANG_PUBKEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE8ELkixyLcwlZryUQcu1TvPOmI2B7vX83ndnWRUaXm74wFfa5f/lwQNTfrLVHa2PmenpGI6JhIMUJaWZrjmMj90NoKNFSNBuKdm8rYiXsfaz3K36x/1U26HpG0ZxK/V1V";

	const EDITION_POCKET = 0;

	public $username;
	public $protocol;
	public $clientUUID;
	public $clientId;
	public $identityPublicKey;
	public $serverAddress;

	public $chainData;
	public $clientData;
	public $clientDataJwt;
	public $decoded;

	public $languageCode;

	public $xuid = "";

	public function canBeSentBeforeLogin() : bool{
		return true;
	}

	public function decodePayload(){
		$this->protocol = $this->getInt();

		if($this->protocol !== ProtocolInfo::CURRENT_PROTOCOL){
			if($this->protocol > 0xffff){
				$this->offset -= 6;
				$this->protocol = $this->getInt();
			}
			return; //Do not attempt to decode for non-accepted protocols
		}

		$this->setBuffer($this->getString(), 0);

		$this->chainData = json_decode($this->get($this->getLInt()));
		$chainKey = self::MOJANG_PUBKEY;
		foreach($this->chainData->{"chain"} as $chain){
			list($verified, $webtoken) = $this->decodeToken($chain, $chainKey);
			if(isset($webtoken["extraData"])){
				if(isset($webtoken["extraData"]["displayName"])){
					$this->username = $webtoken["extraData"]["displayName"];
				}
				if(isset($webtoken["extraData"]["identity"])){
					$this->clientUUID = $webtoken["extraData"]["identity"];
				}
				if(isset($webtoken["extraData"]["XUID"])){
					$this->xuid = $webtoken["extraData"]["XUID"];
				}
			}
			if($verified and isset($webtoken["identityPublicKey"])){
				if($webtoken["identityPublicKey"] != self::MOJANG_PUBKEY){
					$this->identityPublicKey = $webtoken["identityPublicKey"];
				}
			}
		}

		$this->clientDataJwt = $this->get($this->getLInt());
		$this->decoded = $this->decodeToken($this->clientDataJwt, $this->identityPublicKey);
		$this->clientData = $this->decoded[1];

		$this->clientId = $this->clientData["ClientRandomId"] ?? null;
		$this->serverAddress = $this->clientData["ServerAddress"] ?? null;

		if(isset($this->clientData["LanguageCode"])){
			$this->languageCode = $this->clientData["LanguageCode"];
		}
 	}

	public function encodePayload(){
		//TODO
	}

	public function decodeToken($token, $key = null){
		if($key === null){
			$tokens = explode(".", $token);
			list($headB64, $payloadB64, $sigB64) = $tokens;

			return array(false, json_decode(base64_decode($payloadB64), true));
		}else{
			if(extension_loaded("openssl")){
				$tokens = explode(".", $token);
				list($headB64, $payloadB64, $sigB64) = $tokens;
				$sig = base64_decode(strtr($sigB64, '-_', '+/'), true);
				$rawLen = 48; // ES384
				for ($i = $rawLen; $i > 0 and $sig[$rawLen - $i] == chr(0); $i--) {
				}
				$j = $i + (ord($sig[$rawLen - $i]) >= 128 ? 1 : 0);
				for ($k = $rawLen; $k > 0 and $sig[2 * $rawLen - $k] == chr(0); $k--) {
				}
				$l = $k + (ord($sig[2 * $rawLen - $k]) >= 128 ? 1 : 0);
				$len = 2 + $j + 2 + $l;
				$derSig = chr(48);
				if ($len > 255) {
					throw new \RuntimeException("Invalid signature format");
				} elseif ($len >= 128) {
					$derSig .= chr(81);
				}
				$derSig .= chr($len) . chr(2) . chr($j);
				$derSig .= str_repeat(chr(0), $j - $i) . substr($sig, $rawLen - $i, $i);
				$derSig .= chr(2) . chr($l);
				$derSig .= str_repeat(chr(0), $l - $k) . substr($sig, 2 * $rawLen - $k, $k);
				$verified = openssl_verify($headB64 . "." . $payloadB64, $derSig, "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----\n", OPENSSL_ALGO_SHA384) === 1;
			}else{
				$tokens = explode(".", $token);
				list($headB64, $payloadB64, $sigB64) = $tokens;
				$verified = false;
			}
			return array($verified, json_decode(base64_decode($payloadB64), true));
		}
	}

	public function handle(NetworkSession $session) : bool{
		return $session->handleLogin($this);
	}
}
