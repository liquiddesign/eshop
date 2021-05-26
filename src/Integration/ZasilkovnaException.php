<?php


namespace Eshop\Integration;

class ZasilkovnaException extends \Exception
{
	public const MISSING_API_KEY = 1;
	public const MISSING_PICKUP_POINT_TYPE = 2;
	public const INVALID_RESPONSE = 3;
	public const JSON_PARSE_ERROR = 4;
}