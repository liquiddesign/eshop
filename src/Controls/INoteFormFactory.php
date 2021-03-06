<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface INoteFormFactory
{
	public function create(): NoteForm;
}