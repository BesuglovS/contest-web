<?php

/**
 * DTO-класс для результата одного теста.
 * Заменяет ассоциативный массив для типизации и автодополнения в IDE.
 */
class TestResult
{
    public int $number;
    public bool $isPublic;
    public string $status;
    public string $output;
    public string $error;
    public float $time;
    public int $memory;
    public string $input;
    public string $expected;

    public function __construct(
        int $number,
        bool $isPublic,
        string $status,
        string $output,
        string $error,
        float $time,
        int $memory,
        string $input,
        string $expected,
    ) {
        $this->number = $number;
        $this->isPublic = $isPublic;
        $this->status = $status;
        $this->output = $output;
        $this->error = $error;
        $this->time = $time;
        $this->memory = $memory;
        $this->input = $input;
        $this->expected = $expected;
    }
}