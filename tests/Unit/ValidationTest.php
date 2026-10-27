<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Validation\ValidationRuleInterface;
use Nora\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testValidatesSupportedTypesAndNestedInput(): void
    {
        $result = (new Validator())->validate(
            [
                'profile' => ['email' => 'user@example.test'],
                'age' => '27',
                'active' => true,
                'name' => 'Nora',
            ],
            [
                'profile.email' => 'required|email',
                'age' => 'integer|min:18',
                'active' => 'boolean',
                'name' => 'string|min:2|max:10',
            ],
        );

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors());
    }

    public function testReportsRequiredAndInvalidFieldErrors(): void
    {
        $result = (new Validator())->validate(
            ['email' => 'invalid', 'age' => 'not-a-number'],
            ['name' => 'required', 'email' => 'required|email', 'age' => 'integer'],
        );

        self::assertFalse($result->isValid());
        self::assertSame(['The field is required.'], $result->errorsFor('name'));
        self::assertSame(['The field failed the email validation rule.'], $result->errorsFor('email'));
        self::assertSame(['The field failed the integer validation rule.'], $result->errorsFor('age'));
    }

    public function testOptionalMissingFieldsAreSkipped(): void
    {
        $result = (new Validator())->validate([], ['nickname' => 'string|min:3']);

        self::assertTrue($result->isValid());
    }

    public function testRunsCustomRule(): void
    {
        $rule = new class implements ValidationRuleInterface {
            public function validate(string $field, mixed $value): ?string
            {
                return $value === 'allowed' ? null : $field . ' is not allowed.';
            }
        };

        $result = (new Validator())->validate(['role' => 'blocked'], ['role' => [$rule]]);

        self::assertSame(['role is not allowed.'], $result->errorsFor('role'));
    }

    public function testRejectsUnknownRuleInsteadOfSilentlyIgnoringIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Validator())->validate(['field' => 'value'], ['field' => 'unknown']);
    }
}
