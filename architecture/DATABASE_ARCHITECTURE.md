# Database Architecture

## Implemented access path

```mermaid
flowchart LR
    Handler[Application handler/service] --> Builder[QueryBuilder]
    Builder -->|SQL identifiers checked; values separate| Connection[PdoConnection]
    Connection -->|prepare and execute| PDO[PDO driver]
    Connection -->|hash and length only| Logger[Optional LoggerInterface]
    MigrationRunner --> PDO
    MigrationFiles[MigrationInterface files] --> MigrationRunner
    MigrationRunner --> History[nora_migrations table]
```

`PdoConnection` configures exception mode, associative fetches, and native
prepares. `QueryBuilder` currently builds SELECT queries; table/column names
must match a conservative identifier grammar and operators are allowlisted.
Raw SQL remains a trusted caller responsibility. Bound values are never
included in logs.

Transactions commit on success and roll back on thrown failures when the
connection wrapper owns the transaction. Migrations are named
`YYYY_description.php` and return a `MigrationInterface`. The runner tracks
applied names and batches in `nora_migrations`.

MySQL DDL commonly auto-commits. The migration runner avoids claiming that a
MySQL DDL migration is atomic; a failure can leave schema changes without a
history record. Real MySQL/MariaDB behavior has not been tested because no
local service was available. Migration tests use a PDO test double.

Only MySQL/MariaDB is the supported baseline. Other drivers have not been
operationally validated.

## Transaction lifecycle

```mermaid
sequenceDiagram
    participant Caller
    participant Connection as PdoConnection
    participant PDO
    Caller->>Connection: transaction(callback)
    Connection->>PDO: beginTransaction if none active
    Connection->>Caller: invoke callback(connection)
    Caller-->>Connection: result
    Connection->>PDO: commit owned transaction
    Connection-->>Caller: result
    Note over Connection,PDO: On Throwable, rollback owned active transaction and rethrow
```

Nested calls do not create savepoints: only the call that starts the transaction
commits or rolls it back. MySQL DDL may auto-commit and is not made atomic by
this helper.
