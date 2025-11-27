<?php

namespace Eshop\DB;

enum OfferState : string
{
	case Created = 'created';
	case Sent = 'sent';
	case Approved = 'approved';
	case AwaitingManagerApproval = 'awaiting_manager_approval';
	case ManagerApproved = 'manager_approved';

	case Completed = 'completed';
	case Canceled = 'canceled';
}
