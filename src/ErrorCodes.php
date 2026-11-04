<?php

// Generated from the SurSolver catalog (GET /v1/task-types) by the spec repository
// tools/templates/packagist.ts. Catalog fetched at 2026-11-03T21:16:22.334Z. DO NOT EDIT.

declare(strict_types=1);

namespace SurSolver;

/** The complete errorCode vocabulary of the solver endpoints. */
final class ErrorCodes
{
    public const string ERROR_KEY_DOES_NOT_EXIST = 'ERROR_KEY_DOES_NOT_EXIST';
    public const string ERROR_WRONG_CLIENT_KEY = 'ERROR_WRONG_CLIENT_KEY';
    public const string ERROR_ZERO_BALANCE = 'ERROR_ZERO_BALANCE';
    public const string ERROR_NO_SLOT_AVAILABLE = 'ERROR_NO_SLOT_AVAILABLE';
    public const string ERROR_TYPE_NOT_SUPPORTED = 'ERROR_TYPE_NOT_SUPPORTED';
    public const string ERROR_TASK_NOT_FOUND = 'ERROR_TASK_NOT_FOUND';
    public const string ERROR_TASK_EXPIRED = 'ERROR_TASK_EXPIRED';
    public const string ERROR_TASK_TIMEOUT = 'ERROR_TASK_TIMEOUT';
    public const string ERROR_FAILED = 'ERROR_FAILED';
    public const string ERROR_INVALID_REQUEST = 'ERROR_INVALID_REQUEST';
    public const string ERROR_WRONG_USERAGENT = 'ERROR_WRONG_USERAGENT';
    public const string ERROR_INTERNAL_ERROR = 'ERROR_INTERNAL_ERROR';

    /** All codes in catalog order. */
    public const array ALL = [
        self::ERROR_KEY_DOES_NOT_EXIST,
        self::ERROR_WRONG_CLIENT_KEY,
        self::ERROR_ZERO_BALANCE,
        self::ERROR_NO_SLOT_AVAILABLE,
        self::ERROR_TYPE_NOT_SUPPORTED,
        self::ERROR_TASK_NOT_FOUND,
        self::ERROR_TASK_EXPIRED,
        self::ERROR_TASK_TIMEOUT,
        self::ERROR_FAILED,
        self::ERROR_INVALID_REQUEST,
        self::ERROR_WRONG_USERAGENT,
        self::ERROR_INTERNAL_ERROR,
    ];
}
