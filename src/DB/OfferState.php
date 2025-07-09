<?php

namespace Eshop\DB;

enum OfferState : string
{
	case Created = 'created';
	case Sent = 'sent';
	case Approved = 'approved';
	case Canceled = 'canceled';
}
