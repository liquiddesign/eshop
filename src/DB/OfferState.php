<?php

namespace Eshop\DB;

enum OfferState
{
	case Created;
	case Approved;
	case Completed;
	case Canceled;
}
