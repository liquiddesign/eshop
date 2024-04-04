<?php

namespace Eshop\Common;

enum CartListEditMode : int
{
	case NONE = 0;
	case AMOUNT = 1;
	case FULL = 2;
}
