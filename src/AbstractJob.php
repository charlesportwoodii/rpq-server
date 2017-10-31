<?php declare(strict_types=1);

namespace RPQ\Queue;

abstract class AbstractJob
{
    protected $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    protected function getId()
    {
        return $this->id;
    }

    public function perform(array $args = []): int {}
}