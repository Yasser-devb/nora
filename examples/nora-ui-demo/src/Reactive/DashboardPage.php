<?php

declare(strict_types=1);

namespace NoraUiDemo\Reactive;

use Nora\Reactive\Component;
use Nora\Reactive\Components\Alert;
use Nora\Reactive\Components\Accordion;
use Nora\Reactive\Components\Avatar;
use Nora\Reactive\Components\Badge;
use Nora\Reactive\Components\Breadcrumb;
use Nora\Reactive\Components\Button;
use Nora\Reactive\Components\Checkbox;
use Nora\Reactive\Components\Card;
use Nora\Reactive\Components\DataTable;
use Nora\Reactive\Components\Dropdown;
use Nora\Reactive\Components\EmptyState;
use Nora\Reactive\Components\Field;
use Nora\Reactive\Components\Layout;
use Nora\Reactive\Components\Modal;
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
use Nora\Reactive\Components\Tabs;
use Nora\Reactive\Components\Textarea;
use Nora\Reactive\Components\Toast;
use Nora\Reactive\Components\Tooltip;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\Slots;
use Nora\Reactive\State;
use Nora\Reactive\UI;
use Nora\Validation\ValidationRuleInterface;

final class DashboardPage extends Component
{
    public function initialState(): array
    {
        return [
            'count' => 0,
            'profile' => null,
            'permissions' => [
                'increment' => true,
                'profile' => true,
                'records' => true,
            ],
            'records' => [
                ['id' => 'p-1001', 'name' => 'Amina El Idrissi', 'email' => 'amina@example.test', 'role' => 'Admin', 'status' => 'Active'],
                ['id' => 'p-1002', 'name' => 'Youssef Benali', 'email' => 'youssef@example.test', 'role' => 'Editor', 'status' => 'Active'],
                ['id' => 'p-1003', 'name' => 'Salma Amrani', 'email' => 'salma@example.test', 'role' => 'Viewer', 'status' => 'Invited'],
                ['id' => 'p-1004', 'name' => 'Omar Haddad', 'email' => 'omar@example.test', 'role' => 'Editor', 'status' => 'Active'],
                ['id' => 'p-1005', 'name' => 'Lina Mansouri', 'email' => 'lina@example.test', 'role' => 'Viewer', 'status' => 'Disabled'],
                ['id' => 'p-1006', 'name' => 'Karim Alaoui', 'email' => 'karim@example.test', 'role' => 'Admin', 'status' => 'Active'],
                ['id' => 'p-1007', 'name' => 'Nadia Zahra', 'email' => 'nadia@example.test', 'role' => 'Editor', 'status' => 'Invited'],
            ],
            'next_record_id' => 1008,
            'query' => '',
            'status_filter' => 'All',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => 1,
            'editing' => null,
            'toast' => null,
            'preferences' => [
                'digest' => false,
                'notifications' => true,
                'density' => 'Comfortable',
                'timezone' => 'Africa/Casablanca',
                'note' => '',
            ],
        ];
    }

    protected function view(State $state): SafeHtml
    {
        $profile = $state->get('profile');
        $records = $state->get('records');
        $statusFilter = $state->get('status_filter');
        $matchingRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => $statusFilter === 'All' || $record['status'] === $statusFilter,
        ));
        $editingRecord = null;
        if ($state->get('editing') !== null) {
            foreach ($records as $record) {
                if ($record['id'] === $state->get('editing')) {
                    $editingRecord = $record;
                    break;
                }
            }
        }
        $profileAlert = UI::fragment([]);
        if ($profile !== null) {
            $profileAlert = Alert::render(
                ['variant' => 'success', 'label' => 'Profile saved'],
                new Slots(['default' => UI::element('p', [], [
                    'Profile saved for ',
                    UI::element('strong', [], $profile['name']),
                    ' (',
                    $profile['email'],
                    ').',
                ])]),
            );
        }

        $recordTable = $matchingRecords === []
            ? EmptyState::render([
                'title' => 'No matching people',
                'description' => 'Change the search or status filter to see records.',
            ], new Slots([
                'action' => Button::render([
                    'variant' => 'outline',
                    'action' => 'filter-records',
                    'arguments' => ['query' => '', 'status' => 'All'],
                ], 'Clear filters'),
            ]))
            : DataTable::render([
                'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'role', 'label' => 'Role'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'actions', 'label' => 'Actions', 'sortable' => false],
                ],
                'caption' => 'People in the workspace',
                'query' => $state->get('query'),
                'sort' => $state->get('sort'),
                'direction' => $state->get('direction'),
                'page' => $state->get('page'),
                'per_page' => 3,
                'sort_action' => 'sort-records',
                'page_action' => 'page-records',
                'action_arguments' => ['status' => $statusFilter],
            ], $matchingRecords, static fn (array $record): string => $record['id'],
                static fn (array $record): array => [
                    $record['name'],
                    $record['email'],
                    $record['role'],
                    Badge::render([
                        'variant' => match ($record['status']) {
                            'Active' => 'success',
                            'Invited' => 'warning',
                            default => 'neutral',
                        },
                    ], $record['status']),
                    Button::render([
                        'variant' => 'ghost',
                        'size' => 'small',
                        'action' => 'edit-record',
                        'arguments' => ['id' => $record['id']],
                    ], 'Edit'),
                ]);
        $preferences = $state->get('preferences');
        $toastState = $state->get('toast');
        $toastMarkup = UI::fragment([]);
        if (is_array($toastState)) {
            $toastMarkup = Toast::render([
                'id' => 'demo-toast',
                'variant' => $toastState['variant'],
                'dismiss_action' => 'dismiss-toast',
            ], $toastState['message']);
        }

        $showcase = UI::element('section', [
            'class' => 'nora-demo-section',
            'aria-labelledby' => 'foundations-heading',
        ], [
            UI::element('h2', ['id' => 'foundations-heading'], 'Foundations and feedback'),
            Alert::render([
                'variant' => 'info',
                'label' => 'Demo information',
                'dismissible' => true,
            ], new Slots([
                'default' => UI::text('This notice is dismissible without a server round trip.'),
            ])),
            UI::element('div', ['class' => 'nora-demo-grid'], [
                StatCard::render([
                    'label' => 'People',
                    'value' => (string) count($records),
                    'description' => 'Session demo records',
                ]),
                StatCard::render([
                    'label' => 'Reactive updates',
                    'value' => (string) $state->get('count'),
                    'trend' => 'neutral',
                    'trend_label' => 'This session',
                ]),
            ]),
            Breadcrumb::render('You are here', [
                ['label' => 'Workspace', 'href' => '#workspace-heading'],
                ['label' => 'UI showcase'],
            ]),
            UI::element('div', ['class' => 'nora-demo-row'], [
                Badge::render(['variant' => 'primary', 'dot' => true], 'Primary'),
                Badge::render(['variant' => 'success'], 'Active'),
                Badge::render(['variant' => 'warning'], 'Invitation pending'),
                Badge::render(['variant' => 'danger'], 'Action required'),
                Avatar::render(['label' => 'Amina El Idrissi']),
                Avatar::render([
                    'label' => 'Profile image for Youssef',
                    'src' => '/avatar.svg',
                    'initials' => 'YB',
                ]),
            ]),
            UI::element('div', ['class' => 'nora-demo-row'], [
                Spinner::render(['label' => 'Loading preview']),
                Spinner::render(['size' => 'small', 'announce' => false]),
                Skeleton::render(['variant' => 'text', 'width' => 'three-quarter']),
            ]),
            UI::element('label', [], [
                'Deployment progress',
                Progress::render(['value' => 65, 'label' => 'Deployment progress']),
            ]),
            Separator::render(),
            Tooltip::render([
                'id' => 'keyboard-tooltip',
                'content' => 'This hint is available on keyboard focus as well as hover.',
            ], UI::element('button', ['type' => 'button', 'class' => 'nora-button nora-button--outline'], 'Focus for a hint')),
            Alert::render(['variant' => 'info'], new Slots([
                'title' => UI::element('strong', [], 'Safe rendering'),
                'default' => UI::text('Component text is escaped by default.'),
            ])),
            Accordion::render('Component notes', [
                [
                    'id' => 'demo-accordion-a',
                    'title' => 'Server-rendered controls',
                    'content' => UI::element('p', [], 'Form values are validated by registered server actions.'),
                    'open' => true,
                ],
                [
                    'id' => 'demo-accordion-b',
                    'title' => 'Native browser semantics',
                    'content' => UI::element('p', [], 'Disclosure and progress use native HTML elements.'),
                ],
            ], false),
            UI::element('div', ['class' => 'nora-demo-row'], [
                EmptyState::render([
                    'title' => 'Empty state example',
                    'description' => 'This pattern appears when the directory has no matching records.',
                ], new Slots(['icon' => UI::element('span', ['aria-hidden' => true], '—')])),
                Toast::render([
                    'id' => 'toast-preview',
                    'variant' => 'info',
                ], 'Dismiss this notification preview.'),
            ]),
        ]);

        $recordId = $editingRecord['id'] ?? '';
        $recordModal = Modal::render([
            'id' => 'record-editor',
            'title' => $editingRecord === null ? 'Add a person' : 'Edit person',
            'description' => 'The server validates every submitted field.',
            'trigger' => 'Add a person',
            'open' => $state->get('editing') !== null,
            'close_action' => 'cancel-edit',
        ], ReactiveForm::render([
            'action_name' => 'save-record',
            'success_message' => 'Person saved',
        ], UI::fragment([
            UI::element('input', [
                'type' => 'hidden',
                'name' => 'record_id',
                'value' => $recordId,
                'data-nora-field' => 'record_id',
            ]),
            Field::render([
                'name' => 'record_name',
                'label' => 'Name',
                'required' => true,
            ], UI::element('input', [
                'type' => 'text',
                'autocomplete' => 'name',
                'value' => $editingRecord['name'] ?? '',
            ])),
            Field::render([
                'name' => 'record_email',
                'label' => 'Email',
                'required' => true,
            ], UI::element('input', [
                'type' => 'text',
                'inputmode' => 'email',
                'autocomplete' => 'email',
                'value' => $editingRecord['email'] ?? '',
            ])),
            Select::render([
                'name' => 'record_role',
                'label' => 'Role',
                'required' => true,
                'placeholder' => 'Choose a role',
                'value' => $editingRecord['role'] ?? '',
            ], [
                ['value' => 'Admin', 'label' => 'Admin'],
                ['value' => 'Editor', 'label' => 'Editor'],
                ['value' => 'Viewer', 'label' => 'Viewer'],
            ]),
            Button::render(['type' => 'submit'], 'Save person'),
        ])),
        );

        $recordsCard = Card::render([
            'as' => 'article',
            'labelled_by' => 'workspace-heading',
        ], new Slots([
            'header' => UI::element('div', ['class' => 'nora-card__heading'], [
                UI::element('h2', ['id' => 'workspace-heading'], 'People directory'),
                $recordModal,
            ]),
            'default' => UI::fragment([
                ReactiveForm::render([
                    'action_name' => 'filter-records',
                    'action_url' => '/#workspace-heading',
                    'success_message' => 'Directory filters updated',
                ], UI::element('div', ['class' => 'nora-demo-filter'], [
                    SearchField::render([
                        'name' => 'query',
                        'label' => 'Search people',
                        'value' => $state->get('query'),
                    ]),
                    Select::render([
                        'name' => 'status',
                        'label' => 'Status filter',
                        'value' => $statusFilter,
                        'placeholder' => 'All statuses',
                    ], [
                        ['value' => 'All', 'label' => 'All statuses'],
                        ['value' => 'Active', 'label' => 'Active'],
                        ['value' => 'Invited', 'label' => 'Invited'],
                        ['value' => 'Disabled', 'label' => 'Disabled'],
                    ]),
                    Button::render(['type' => 'submit'], 'Apply filters'),
                ]), ['id' => 'directory-filters']),
                $recordTable,
            ]),
        ]));

        $settingsCard = Card::render([
            'as' => 'article',
            'labelled_by' => 'settings-heading',
        ], new Slots([
            'header' => UI::element('h2', ['id' => 'settings-heading'], 'Preferences form'),
            'default' => ReactiveForm::render([
                'action_name' => 'save-preferences',
                'success_message' => 'Preferences saved',
            ], UI::fragment([
                Checkbox::render([
                    'name' => 'digest',
                    'label' => 'Receive a weekly digest',
                    'hint' => 'A short summary of workspace activity.',
                    'checked' => $preferences['digest'],
                ]),
                SwitchControl::render([
                    'name' => 'notifications',
                    'label' => 'Enable notifications',
                    'checked' => $preferences['notifications'],
                ]),
                RadioGroup::render([
                    'name' => 'density',
                    'label' => 'Display density',
                    'value' => $preferences['density'],
                ], [
                    ['value' => 'Comfortable', 'label' => 'Comfortable'],
                    ['value' => 'Compact', 'label' => 'Compact'],
                ]),
                Select::render([
                    'name' => 'timezone',
                    'label' => 'Timezone',
                    'value' => $preferences['timezone'],
                ], [
                    ['value' => 'Africa/Casablanca', 'label' => 'Casablanca'],
                    ['value' => 'UTC', 'label' => 'UTC'],
                ]),
                Textarea::render([
                    'name' => 'note',
                    'label' => 'Notes',
                    'value' => $preferences['note'],
                    'rows' => 3,
                    'hint' => 'Saved only in this reactive session.',
                ]),
                Button::render(['type' => 'submit'], 'Save preferences'),
            ]), ['id' => 'preferences-form']),
        ]));

        $content = UI::fragment([
            $profileAlert,
            $toastMarkup,
            $showcase,
            $recordsCard,
            $settingsCard,
            Card::render([
                'as' => 'article',
                'labelled_by' => 'profile-heading',
            ], new Slots([
                'header' => UI::element('h2', ['id' => 'profile-heading'], 'Profile form'),
                'default' => ReactiveForm::render([
                    'action_name' => 'submit-profile',
                    'success_message' => 'Profile saved',
                ], UI::fragment([
                    Field::render(
                        ['name' => 'name', 'label' => 'Full name', 'required' => true],
                        UI::element('input', [
                            'type' => 'text',
                            'autocomplete' => 'name',
                        ]),
                    ),
                    Field::render(
                        [
                            'name' => 'email',
                            'label' => 'Email address',
                            'required' => true,
                            'hint' => 'Use an address such as you@example.test.',
                        ],
                        UI::element('input', [
                            'type' => 'text',
                            'inputmode' => 'email',
                            'autocomplete' => 'email',
                        ]),
                    ),
                    Button::render(['type' => 'submit'], 'Save profile'),
                ]), ['id' => 'profile-form']),
            ])),
            Card::render([
                'as' => 'article',
                'labelled_by' => 'counter-heading',
            ], new Slots([
                'header' => UI::element('h2', ['id' => 'counter-heading'], 'Authorized reactive action'),
                'default' => UI::fragment([
                    UI::element('p', [], [
                        'Successful updates: ',
                        UI::element('output', ['id' => 'visit-count'], $state->get('count')),
                    ]),
                    Button::render([
                        'action' => 'increment',
                        'arguments' => ['amount' => 1],
                    ], 'Increment'),
                    Button::render([
                        'variant' => 'outline',
                        'action' => 'denied-example',
                    ], 'Try a denied action'),
                ]),
            ])),
            Card::render([
                'as' => 'article',
                'labelled_by' => 'controls-heading',
            ], new Slots([
                'header' => UI::element('h2', ['id' => 'controls-heading'], 'Interactive controls'),
                'default' => UI::fragment([
                    Modal::render(
                        [
                            'id' => 'demo-help',
                            'title' => 'About this demo',
                            'trigger' => 'Open help',
                        ],
                        UI::element('p', [], 'This dialog uses the native browser dialog element.'),
                    ),
                    Tabs::render('demo-tabs', 'Demo information', [
                        [
                            'id' => 'overview',
                            'label' => 'Overview',
                            'content' => UI::element('p', [], 'Components are composed from safe markup.'),
                        ],
                        [
                            'id' => 'security',
                            'label' => 'Security',
                            'content' => UI::element('p', [], 'Actions are explicitly registered and authorized.'),
                        ],
                        [
                            'id' => 'disabled',
                            'label' => 'Unavailable',
                            'content' => UI::element('p', [], 'This tab is intentionally disabled.'),
                            'disabled' => true,
                        ],
                    ]),
                    Dropdown::render(
                        ['label' => 'More information'],
                        UI::element('a', ['href' => '#workspace-heading'], 'View the people directory'),
                    ),
                ]),
            ])),
        ]);

        return Layout::render([
            'title' => 'Nora UI integration demo',
        ], new Slots([
            'navigation' => UI::fragment([
                UI::element('a', ['href' => '#foundations-heading'], 'Foundations'),
                UI::element('a', ['href' => '#workspace-heading'], 'People directory'),
                UI::element('a', ['href' => '#profile-heading'], 'Profile'),
                UI::element('a', ['href' => '#counter-heading'], 'Counter'),
                UI::element('a', ['href' => '#settings-heading'], 'Preferences'),
                UI::element('a', ['href' => '#controls-heading'], 'Controls'),
            ]),
            'header' => UI::element('p', [], 'A composed example using Nora’s documented public UI APIs.'),
            'default' => $content,
            'footer' => UI::element('p', [], 'Development demo only.'),
        ]));
    }

    public function actions(): array
    {
        return [
            'increment' => new ReactiveAction(
                ['amount' => 'required|integer|min:1|max:1'],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'count' => $state['count'] + $arguments['amount'],
                ],
                static fn (array $state): bool => $state['permissions']['increment'] === true,
            ),
            'submit-profile' => new ReactiveAction(
                [
                    'name' => 'required|string|max:60',
                    'email' => 'required|email',
                ],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'profile' => [
                        'name' => $arguments['name'],
                        'email' => $arguments['email'],
                    ],
                ],
                static fn (array $state): bool => $state['permissions']['profile'] === true,
            ),
            'filter-records' => new ReactiveAction(
                [
                    'query' => 'string|max:80',
                    'status' => ['required', 'string', new DemoChoiceRule(['All', 'Active', 'Invited', 'Disabled'])],
                ],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'query' => $arguments['query'] ?? '',
                    'status_filter' => $arguments['status'],
                    'page' => 1,
                    'toast' => null,
                ],
                static fn (array $state): bool => $state['permissions']['records'] === true,
            ),
            'sort-records' => new ReactiveAction(
                [
                    'sort' => ['required', 'string', new DemoChoiceRule(['name', 'email', 'role', 'status'])],
                    'direction' => ['required', 'string', new DemoChoiceRule(['asc', 'desc'])],
                    'query' => 'string|max:80',
                    'status' => ['required', 'string', new DemoChoiceRule(['All', 'Active', 'Invited', 'Disabled'])],
                ],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'sort' => $arguments['sort'],
                    'direction' => $arguments['direction'],
                    'query' => $arguments['query'] ?? '',
                    'status_filter' => $arguments['status'],
                    'page' => 1,
                ],
                static fn (array $state): bool => $state['permissions']['records'] === true,
            ),
            'page-records' => new ReactiveAction(
                [
                    'page' => 'required|integer|min:1|max:1000',
                    'query' => 'string|max:80',
                    'status' => ['required', 'string', new DemoChoiceRule(['All', 'Active', 'Invited', 'Disabled'])],
                ],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'page' => $arguments['page'],
                    'query' => $arguments['query'] ?? '',
                    'status_filter' => $arguments['status'],
                ],
                static fn (array $state): bool => $state['permissions']['records'] === true,
            ),
            'edit-record' => new ReactiveAction(
                ['id' => 'required|string|max:32'],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'editing' => $arguments['id'],
                    'toast' => null,
                ],
                static fn (array $state, array $arguments): bool =>
                    $state['permissions']['records'] === true
                    && count(array_filter(
                        $state['records'],
                        static fn (array $record): bool => $record['id'] === $arguments['id'],
                    )) === 1,
            ),
            'cancel-edit' => new ReactiveAction(
                [],
                static fn (array $state): array => [...$state, 'editing' => null],
                static fn (array $state): bool => $state['permissions']['records'] === true,
            ),
            'save-record' => new ReactiveAction(
                [
                    'record_id' => 'string|max:32',
                    'record_name' => 'required|string|max:60',
                    'record_email' => 'required|email',
                    'record_role' => ['required', 'string', new DemoChoiceRule(['Admin', 'Editor', 'Viewer'])],
                ],
                static function (array $state, array $arguments): array {
                    $records = $state['records'];
                    $id = $arguments['record_id'] ?? '';
                    $updated = false;
                    foreach ($records as &$record) {
                        if ($id !== '' && $record['id'] === $id) {
                            $record['name'] = $arguments['record_name'];
                            $record['email'] = $arguments['record_email'];
                            $record['role'] = $arguments['record_role'];
                            $updated = true;
                            break;
                        }
                    }
                    unset($record);
                    if (!$updated) {
                        $records[] = [
                            'id' => 'p-' . $state['next_record_id'],
                            'name' => $arguments['record_name'],
                            'email' => $arguments['record_email'],
                            'role' => $arguments['record_role'],
                            'status' => 'Active',
                        ];
                    }

                    return [
                        ...$state,
                        'records' => $records,
                        'next_record_id' => $updated ? $state['next_record_id'] : $state['next_record_id'] + 1,
                        'editing' => null,
                        'page' => 1,
                        'toast' => [
                            'variant' => 'success',
                            'message' => $updated ? 'Person updated.' : 'Person created.',
                        ],
                    ];
                },
                static fn (array $state, array $arguments): bool =>
                    $state['permissions']['records'] === true
                    && (($arguments['record_id'] ?? '') === ''
                        || count(array_filter(
                            $state['records'],
                            static fn (array $record): bool => $record['id'] === $arguments['record_id'],
                        )) === 1),
            ),
            'save-preferences' => new ReactiveAction(
                [
                    'digest' => 'required|boolean',
                    'notifications' => 'required|boolean',
                    'density' => ['required', 'string', new DemoChoiceRule(['Comfortable', 'Compact'])],
                    'timezone' => ['required', 'string', new DemoChoiceRule(['Africa/Casablanca', 'UTC'])],
                    'note' => 'string|max:160',
                ],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'preferences' => [
                        'digest' => $arguments['digest'],
                        'notifications' => $arguments['notifications'],
                        'density' => $arguments['density'],
                        'timezone' => $arguments['timezone'],
                        'note' => $arguments['note'] ?? '',
                    ],
                    'toast' => ['variant' => 'success', 'message' => 'Preferences saved.'],
                ],
                static fn (array $state): bool => $state['permissions']['profile'] === true,
            ),
            'dismiss-toast' => new ReactiveAction(
                [],
                static fn (array $state): array => [...$state, 'toast' => null],
                static fn (array $state): bool => $state['permissions']['profile'] === true,
            ),
            'denied-example' => new ReactiveAction(
                [],
                static fn (array $state): array => $state,
                static fn (): bool => false,
            ),
        ];
    }
}

final readonly class DemoChoiceRule implements ValidationRuleInterface
{
    /**
     * @param list<string> $choices
     */
    public function __construct(private array $choices)
    {
    }

    public function validate(string $field, mixed $value): ?string
    {
        return is_string($value) && in_array($value, $this->choices, true)
            ? null
            : 'Choose one of the available options.';
    }
}
