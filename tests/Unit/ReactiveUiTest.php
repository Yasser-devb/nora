<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Reactive\Component;
use Nora\Reactive\Components\Alert;
use Nora\Reactive\Components\Accordion;
use Nora\Reactive\Components\Avatar;
use Nora\Reactive\Components\Badge;
use Nora\Reactive\Components\Button;
use Nora\Reactive\Components\Card;
use Nora\Reactive\Components\Checkbox;
use Nora\Reactive\Components\Breadcrumb;
use Nora\Reactive\Components\DataTable;
use Nora\Reactive\Components\EmptyState;
use Nora\Reactive\Components\Field;
use Nora\Reactive\Components\Layout;
use Nora\Reactive\Components\Modal;
use Nora\Reactive\Components\Pagination;
use Nora\Reactive\Components\Progress;
use Nora\Reactive\Components\RadioGroup;
use Nora\Reactive\Components\ReactiveForm;
use Nora\Reactive\Components\SearchField;
use Nora\Reactive\Components\Select;
use Nora\Reactive\Components\Separator;
use Nora\Reactive\Components\Skeleton;
use Nora\Reactive\Components\Spinner;
use Nora\Reactive\Components\StatCard;
use Nora\Reactive\Components\SwitchControl;
use Nora\Reactive\Components\Table;
use Nora\Reactive\Components\Textarea;
use Nora\Reactive\Components\Toast;
use Nora\Reactive\Components\Tooltip;
use Nora\Reactive\Props;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\State;
use Nora\Reactive\UI;
use PHPUnit\Framework\TestCase;

final class ReactiveUiTest extends TestCase
{
    public function testElementCompositionEscapesTextAndSerializesBooleanAttributes(): void
    {
        $markup = UI::fragment([
            UI::element('h2', ['aria-expanded' => true], 'Hello <Nora>'),
            UI::element('input', ['disabled' => true, 'value' => '"quoted"']),
            UI::when(false, UI::element('p', [], 'not rendered')),
        ])->toHtml();

        self::assertSame(
            '<h2 aria-expanded="true">Hello &lt;Nora&gt;</h2>'
                . '<input disabled="" value="&quot;quoted&quot;">',
            $markup,
        );
    }

    public function testUnsafeTagsAttributesAndUrlsAreRejected(): void
    {
        foreach ([
            static fn () => UI::element('script', [], 'alert(1)'),
            static fn () => UI::element('button', ['oNcLiCk' => 'alert(1)']),
            static fn () => UI::element('iframe', ['srcdoc' => '<script>alert(1)</script>']),
            static fn () => UI::element('div', ['style' => 'color:red']),
            static fn () => UI::element('a', ['href' => 'javascript:alert(1)'], 'Open'),
            static fn () => UI::element('img', ['src' => 'data:text/html,hi']),
            static fn () => UI::element('object', ['data' => 'javascript:alert(1)']),
            static fn () => UI::element('img', ['srcset' => 'javascript:alert(1) 1x']),
            static fn () => UI::element('a', ['href' => '/safe', 'HREF' => 'javascript:alert(1)']),
            static fn () => UI::element('meta', [
                'http-equiv' => 'refresh',
                'content' => '0;url=javascript:alert(1)',
            ]),
        ] as $buildUnsafeMarkup) {
            try {
                $buildUnsafeMarkup();
                self::fail('Unsafe markup should be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEachAddsStableKeysAndRejectsDuplicateKeys(): void
    {
        $markup = UI::each(
            [['id' => 7, 'label' => '<Seven>'], ['id' => 9, 'label' => 'Nine']],
            static fn (array $item): int => $item['id'],
            static fn (array $item): SafeHtml => UI::element('li', [], $item['label']),
        )->toHtml();

        self::assertSame(
            '<li data-nora-key="7">&lt;Seven&gt;</li><li data-nora-key="9">Nine</li>',
            $markup,
        );

        $this->expectException(InvalidArgumentException::class);
        UI::each(
            [['id' => 1], ['id' => 1]],
            static fn (array $item): int => $item['id'],
            static fn (): SafeHtml => UI::element('li'),
        );
    }

    public function testActionButtonUsesProtocolAttributesAndEncodesArgumentsAsAnObject(): void
    {
        $markup = UI::actionButton(
            'save',
            'Save <profile>',
            ['name' => '"Amina"'],
            ['class' => 'button'],
        )->toHtml();

        self::assertSame(
            '<button class="button" type="button" data-nora-action="save" '
                . 'data-nora-args="{&quot;name&quot;:&quot;\\u0022Amina\\u0022&quot;}">'
                . 'Save &lt;profile&gt;</button>',
            $markup,
        );
        $this->expectException(InvalidArgumentException::class);
        UI::actionButton('save', 'Save', [], ['DATA-NORA-ACTION' => 'other']);
    }

    public function testComponentBaseProvidesImmutableNestedStateAndComputedValues(): void
    {
        $component = new class extends Component {
            public function initialState(): array
            {
                return ['cart' => ['subtotal' => 12, 'tax' => 3]];
            }

            protected function view(State $state): SafeHtml
            {
                $total = $state->computed(
                    static fn (State $snapshot): int =>
                        $snapshot->get('cart.subtotal') + $snapshot->get('cart.tax'),
                );

                return UI::element('output', [], $total);
            }

            public function actions(): array
            {
                return [];
            }
        };

        self::assertSame('<output>15</output>', $component->render($component->initialState()));
    }

    public function testPropsApplyDefaultsAndRejectMissingUnknownOrWronglyTypedValues(): void
    {
        $props = Props::resolve([
            'label' => ['type' => 'string', 'required' => true],
            'variant' => ['type' => 'string', 'default' => 'primary', 'one_of' => ['primary', 'danger']],
            'disabled' => ['type' => 'boolean', 'default' => false],
        ], ['label' => 'Save']);

        self::assertSame('primary', $props->get('variant'));
        self::assertFalse($props->get('disabled'));

        foreach ([
            static fn () => Props::resolve(['label' => ['type' => 'string', 'required' => true]], []),
            static fn () => Props::resolve(['label' => ['type' => 'string']], ['unknown' => 'value']),
            static fn () => Props::resolve(['disabled' => ['type' => 'boolean']], ['disabled' => 1]),
            static fn () => Props::resolve([
                'variant' => ['type' => 'string', 'default' => 'other', 'one_of' => ['primary']],
            ], []),
        ] as $invalidProps) {
            try {
                $invalidProps();
                self::fail('Invalid props should be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSlotsSupportDefaultNamedFallbackAndRequiredContent(): void
    {
        $slots = new Slots([
            'default' => UI::element('p', [], 'Body'),
            'header' => UI::element('h2', [], 'Title'),
        ]);
        $card = Card::render([], $slots)->toHtml();

        self::assertStringContainsString('<header class="nora-card__header"><h2>Title</h2></header>', $card);
        self::assertStringContainsString('<div class="nora-card__body"><p>Body</p></div>', $card);
        self::assertSame('<em>Fallback</em>', $slots->get(
            'missing',
            UI::element('em', [], 'Fallback'),
        )->toHtml());
        self::assertSame('<h2>Title</h2>', $slots->require('header')->toHtml());

        $this->expectException(InvalidArgumentException::class);
        new Slots(['default' => '<img src=x onerror=alert(1)>']);
    }

    public function testRequiredSlotsFailAndOptionalSlotsRenderEmpty(): void
    {
        $optionalCard = Card::render([], new Slots())->toHtml();
        self::assertStringContainsString('<div class="nora-card__body"></div>', $optionalCard);
        self::assertStringNotContainsString('nora-card__header', $optionalCard);
        self::assertStringNotContainsString('nora-card__footer', $optionalCard);

        $this->expectException(InvalidArgumentException::class);
        (new Slots())->require('header');
    }

    public function testCardMetadataAndModalCloseActionRenderAccessibly(): void
    {
        $card = Card::render([
            'title' => 'People',
            'description' => 'Workspace records',
        ], new Slots([
            'default' => UI::element('p', [], 'Directory contents'),
            'actions' => Button::render([], 'Add a person'),
        ]))->toHtml();
        self::assertStringContainsString('<h2>People</h2>', $card);
        self::assertStringContainsString('nora-card__description">Workspace records', $card);
        self::assertStringContainsString('nora-card__actions', $card);
        self::assertStringContainsString('Directory contents', $card);

        $modal = Modal::render([
            'id' => 'edit-record',
            'title' => 'Edit record',
            'close_action' => 'cancel-edit',
        ], UI::element('p', [], 'Update this record.'))->toHtml();
        self::assertStringContainsString('data-nora-modal-close-action', $modal);
        self::assertStringContainsString('data-nora-action="cancel-edit"', $modal);
        self::assertStringContainsString('>Close dialog</button>', $modal);
    }

    public function testLayoutComposesAccessibleNamedPageSlots(): void
    {
        $layout = Layout::render(
            ['title' => 'Account'],
            new Slots([
                'navigation' => UI::element('a', ['href' => '/account'], 'Account'),
                'default' => UI::element('p', [], 'Settings'),
                'footer' => UI::element('small', [], 'Nora'),
            ]),
        )->toHtml();

        self::assertStringContainsString('<nav class="nora-layout__navigation" aria-label="Main navigation">', $layout);
        self::assertStringContainsString('<h1>Account</h1>', $layout);
        self::assertStringContainsString('<section class="nora-layout__content" aria-label="Page content">', $layout);
        self::assertStringContainsString('<footer class="nora-layout__footer"><small>Nora</small></footer>', $layout);
    }

    public function testUiKitRendersReactiveButtonsAlertsAndAccessibleFields(): void
    {
        $button = Button::render([
            'variant' => 'danger',
            'size' => 'large',
            'loading' => true,
            'action' => 'save',
            'arguments' => ['id' => 3],
        ], 'Save');
        self::assertStringContainsString('nora-button--danger nora-button--large', $button->toHtml());
        self::assertStringContainsString('data-nora-action="save"', $button->toHtml());
        self::assertStringContainsString('disabled="" aria-busy="true"', $button->toHtml());
        self::assertStringContainsString('class="nora-button__spinner"', $button->toHtml());

        $alert = Alert::render(
            ['variant' => 'warning'],
            new Slots(['default' => UI::text('<untrusted>')]),
        )->toHtml();
        self::assertStringContainsString('role="alert"', $alert);
        self::assertStringContainsString('&lt;untrusted&gt;', $alert);
        self::assertStringContainsString('role="status"', Alert::render(
            [],
            new Slots(['default' => UI::text('Saved.')]),
        )->toHtml());

        $field = Field::render(
            ['name' => 'email', 'label' => 'Email address', 'required' => true, 'hint' => 'Work email'],
            UI::element('input', ['type' => 'email']),
            ['Enter a valid email address.'],
        )->toHtml();
        self::assertStringContainsString('for="email"', $field);
        self::assertStringContainsString('name="email"', $field);
        self::assertStringContainsString('data-nora-field="email"', $field);
        self::assertStringContainsString('aria-invalid="true"', $field);
        self::assertStringContainsString('role="alert"', $field);
        self::assertStringNotContainsString('nora-field__binding', $field);
    }

    public function testReactiveFormAndTableRenderProgressiveSemanticHtml(): void
    {
        $form = ReactiveForm::render(
            ['action_name' => 'save', 'action_url' => '/profile', 'success_message' => 'Saved'],
            UI::element('button', ['type' => 'submit'], 'Save'),
        )->toHtml();
        self::assertStringContainsString('method="post" action="/profile"', $form);
        self::assertStringContainsString('data-nora-form="true" data-nora-action="save"', $form);

        $table = Table::render(
            ['caption' => 'Users'],
            ['Name', 'Role'],
            [['id' => 4, 'name' => '<Amina>', 'role' => 'Admin']],
            static fn (array $row): int => $row['id'],
            static fn (array $row): array => [$row['name'], $row['role']],
        )->toHtml();
        self::assertStringContainsString('<caption>Users</caption>', $table);
        self::assertStringContainsString('<th scope="col">Name</th>', $table);
        self::assertStringContainsString('<tr data-nora-key="4">', $table);
        self::assertStringContainsString('&lt;Amina&gt;', $table);

        $emptyTable = Table::render(
            [],
            ['Name'],
            [],
            static fn (): int => 0,
            static fn (): array => [],
        )->toHtml();
        self::assertStringContainsString('colspan="1"', $emptyTable);
        self::assertStringContainsString('No results.', $emptyTable);
    }

    public function testFoundationComponentsRenderSafeSemanticMarkup(): void
    {
        self::assertStringContainsString('nora-badge--success', Badge::render([
            'variant' => 'success',
            'dot' => true,
        ], 'Active', UI::element('span', [], '✓'))->toHtml());
        self::assertStringContainsString('aria-label="Amina El Idrissi"', Avatar::render([
            'label' => 'Amina El Idrissi',
        ])->toHtml());
        self::assertStringContainsString('nora-avatar--large', Avatar::render([
            'label' => 'Amina',
            'src' => '/avatars/amina.png',
            'size' => 'large',
        ])->toHtml());
        self::assertStringContainsString('role="status" aria-label="Loading records"', Spinner::render([
            'label' => 'Loading records',
        ])->toHtml());
        self::assertStringContainsString('nora-skeleton--width-half', Skeleton::render([
            'variant' => 'card',
            'width' => 'half',
        ])->toHtml());
        self::assertStringContainsString('aria-label="Rollout" max="100" value="65"', Progress::render([
            'value' => 65,
            'label' => 'Rollout',
        ])->toHtml());
        self::assertStringNotContainsString('value=', Progress::render(['label' => 'Waiting'])->toHtml());
        self::assertStringContainsString('role="separator" aria-orientation="vertical"', Separator::render([
            'orientation' => 'vertical',
            'decorative' => false,
        ])->toHtml());
        $tooltip = Tooltip::render([
            'id' => 'help-tip',
            'content' => 'Keyboard hint',
        ], UI::element('button', ['type' => 'button'], 'Help'))->toHtml();
        self::assertStringContainsString('aria-describedby="help-tip"', $tooltip);
        self::assertStringContainsString('id="help-tip" role="tooltip"', $tooltip);
        self::assertStringContainsString('aria-current="page">Settings</span>', Breadcrumb::render(
            'Breadcrumb',
            [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Settings'],
            ],
        )->toHtml());
    }

    public function testApplicationFormAndFeedbackComponentsExposeAccessibleBindings(): void
    {
        $select = Select::render([
            'name' => 'role',
            'label' => 'Role',
            'required' => true,
            'value' => 'editor',
        ], [
            ['value' => 'reader', 'label' => 'Reader'],
            ['value' => 'editor', 'label' => 'Editor'],
        ])->toHtml();
        self::assertStringContainsString('<label for="role">Role</label>', $select);
        self::assertStringContainsString('data-nora-field="role"', $select);
        self::assertStringContainsString('value="editor" selected=""', $select);

        self::assertStringContainsString('checked=""', Checkbox::render([
            'name' => 'digest',
            'label' => 'Weekly digest',
            'checked' => true,
        ])->toHtml());
        $radio = RadioGroup::render([
            'name' => 'density',
            'label' => 'Density',
            'value' => 'compact',
            'hint' => 'Choose spacing.',
        ], [
            ['value' => 'comfortable', 'label' => 'Comfortable'],
            ['value' => 'compact', 'label' => 'Compact'],
        ])->toHtml();
        self::assertStringContainsString('<fieldset class="nora-radio-group"', $radio);
        self::assertStringContainsString('aria-describedby="nora-radio-hint-', $radio);
        self::assertStringContainsString('role="switch"', SwitchControl::render([
            'name' => 'notifications',
            'label' => 'Notifications',
            'checked' => true,
        ])->toHtml());
        $textarea = Textarea::render([
            'name' => 'notes',
            'label' => 'Notes',
            'value' => '<script>alert(1)</script>',
            'rows' => 5,
            'hint' => 'Internal note.',
        ])->toHtml();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $textarea);
        self::assertStringNotContainsString('<script>', $textarea);
        self::assertStringContainsString('aria-describedby="nora-hint-', $textarea);

        self::assertStringContainsString('aria-label="Pagination"', Pagination::render([
            'current' => 2,
            'total' => 3,
            'action' => 'go-page',
        ])->toHtml());
        self::assertStringContainsString('aria-live="assertive"', Toast::render([
            'id' => 'saved-toast',
            'variant' => 'error',
        ], 'Could not save.')->toHtml());
        self::assertStringContainsString('data-nora-toast-dismiss', Toast::render([
            'id' => 'notice',
        ], 'Dismiss me.')->toHtml());
        $accordion = Accordion::render('Help topics', [[
            'id' => 'help-topic',
            'title' => 'How?',
            'content' => UI::element('p', [], 'Details'),
        ]], false)->toHtml();
        self::assertStringContainsString('role="group" aria-label="Help topics"', $accordion);
        self::assertStringContainsString('id="help-topic"', $accordion);
        self::assertStringContainsString('name="Help topics"', $accordion);
        self::assertStringContainsString('nora-stat-card__value">42</p>', StatCard::render([
            'label' => 'People',
            'value' => '42',
        ])->toHtml());
        self::assertStringContainsString('<h2>Nothing here</h2>', EmptyState::render([
            'title' => 'Nothing here',
        ], new Slots())->toHtml());
        $search = SearchField::render(['name' => 'query', 'label' => 'Search'])->toHtml();
        self::assertStringContainsString('type="search"', $search);
        self::assertStringContainsString('data-nora-search-clear', $search);
    }

    public function testComponentOptionValuesAreValidatedBeforeRendering(): void
    {
        $invalidCases = [
            static fn () => Select::render([
                'name' => 'role',
                'label' => 'Role',
                'value' => 'unknown',
            ], [['value' => 'editor', 'label' => 'Editor']]),
            static fn () => Select::render([
                'name' => 'role',
                'label' => 'Role',
            ], [['value' => 'editor', 'label' => 'Editor', 'disabled' => 'false']]),
            static fn () => RadioGroup::render([
                'name' => 'density',
                'label' => 'Density',
                'value' => 'unknown',
            ], [['value' => 'compact', 'label' => 'Compact']]),
            static fn () => RadioGroup::render([
                'name' => 'density',
                'label' => 'Density',
            ], [['value' => 'compact', 'label' => 'Compact', 'disabled' => 'false']]),
            static fn () => Accordion::render('Help', [[
                'id' => 'help-item',
                'title' => 'Help',
                'content' => UI::element('p', [], 'Details'),
                'open' => 'false',
            ]]),
            static fn () => DataTable::render([
                'columns' => [['key' => 'name', 'label' => 'Name']],
                'caption' => 'People',
            ], ['not a row'], static fn (): string => 'row', static fn (): array => ['Name']),
        ];

        foreach ($invalidCases as $invalidCase) {
            try {
                $invalidCase();
                self::fail('Invalid component options should be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDataTableSearchSortAndPaginationOperateOnActualRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Zahra', 'role' => 'Editor'],
            ['id' => 2, 'name' => 'Amina', 'role' => 'Admin'],
            ['id' => 3, 'name' => 'Salma', 'role' => 'Viewer'],
        ];
        $table = DataTable::render([
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'role', 'label' => 'Role'],
            ],
            'caption' => 'People',
            'query' => 'a',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => 1,
            'per_page' => 1,
            'sort_action' => 'sort-people',
            'page_action' => 'page-people',
        ], $rows, static fn (array $row): int => $row['id'], static fn (array $row): array => [
            $row['name'],
            $row['role'],
        ])->toHtml();

        self::assertStringContainsString('data-nora-result-count="3"', $table);
        self::assertStringContainsString('<td>Amina</td>', $table);
        self::assertStringNotContainsString('<td>Zahra</td>', $table);
        self::assertStringContainsString('aria-sort="ascending"', $table);
        self::assertStringContainsString('data-nora-action="sort-people"', $table);
        self::assertStringContainsString('data-nora-action="page-people"', $table);
        $empty = DataTable::render([
            'columns' => [['key' => 'name', 'label' => 'Name']],
            'caption' => 'People',
            'query' => 'missing',
        ], $rows, static fn (array $row): int => $row['id'], static fn (array $row): array => [
            $row['name'],
        ])->toHtml();
        self::assertStringContainsString('data-nora-result-count="0"', $empty);
        self::assertStringContainsString('No matching records.', $empty);
    }
}
