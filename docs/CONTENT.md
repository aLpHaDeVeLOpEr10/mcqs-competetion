# Question Bank, Paper Builder and Snapshots

## Questions

**Who can do what**
- `content.manage` (content managers, admins, super admins) can create, edit, archive and restore.
- `content.view` (adds competition managers) can view questions, answer keys and images.
- There is no hard delete. Archived questions can't be edited or added to papers until restored,
  which brings them back as drafts.

**What a question holds**
- Text, category, difficulty, status, explanation, tags and an optional time limit.
- 2–6 options (4 by default) with **exactly one** correct answer. This is validated in the
  request, and the database also enforces "at most one correct" with a unique index.
- Options are updated in place by position, so their ids stay stable.

**Images**
- JPEG, PNG or WebP up to 2 MB and 4000×4000 px.
- Every upload is decoded with GD and **re-encoded to WebP**, which drops metadata and any
  non-image payload.
- Files are stored under a random 40-character name on the **private** `local` disk.
- They are served only through `admin/questions/{id}/image`, which needs `content.view` and sends
  `nosniff` and a `default-src 'none'` Content-Security-Policy.
- Only server-generated paths are ever read or deleted. SVG and other formats are rejected.

**Auditing**
- Every change writes an audit record (`question.created/updated/archived/restored`) with
  before/after content, including the correct option position.

## CSV import (`Admin → Questions → Import CSV`)

1. **Upload** a CSV file, at most 1 MB and 500 rows. Comma or semicolon separators are
   accepted, with or without a UTF-8 BOM. Windows-1252 text (Excel "CSV") is converted to UTF-8.
2. **Preview.** Every row is validated and the result is cached for 30 minutes under a random
   token tied to the uploader. Nothing is saved yet. Each row shows Ready, Error (with reasons)
   or Duplicate.
3. **Commit.** Only Ready rows are created, in one transaction, followed by one
   `questions.imported` audit record. A token can be committed only once (atomic lock), and only
   by its owner. Duplicates are checked again at commit time.

**Columns**
- Required: `category, question, option_a, option_b, correct`.
- Optional: `option_c … option_f, difficulty, explanation, tags, time_limit, status`.
- Unknown columns, such as `file_path`, reject the whole file.

**Row rules**
- The category must exist and be active (matched by name or slug).
- Options must be filled in order with no gaps.
- `correct` must be the letter of a filled option.
- Difficulty is easy, medium or hard; status is draft or active; time limit is 5–600 seconds.

**Duplicates** are matched on normalized text (case and whitespace are ignored), against the bank
(including archived questions) and earlier rows in the same file.

**Safety**
- The file is only ever parsed as text. No path from the file is used, and nothing is executed.
- Cells starting with `=`, `+`, `-`, `@`, tab or CR are rejected as spreadsheet formulas, unless
  the cell is a plain number such as `-5`.
- **Known trade-off:** a question that genuinely begins with "-" or "+" followed by words is
  rejected too. Enter it through the form instead.

A sample file is available at `admin/questions/import/sample`.

## Quiz papers

**Building a paper**
- Create a paper, then use the builder: add questions (archived ones are excluded), move them up
  or down, remove them, and set per-paper time overrides and points.
- Positions are always renumbered to a continuous 1…n.
- An optional **total number of questions** (`target_question_count`) must be matched exactly
  before the paper can be published.
- **Effective time limit:** the paper item's override, then the question default, then the paper
  default.

**Publishing** (`PaperValidator`) requires:
- 5–200 questions, and exactly the target count if one is set;
- continuous positions;
- every question active, not deleted, with 2–6 non-empty options and exactly one correct;
- every effective time limit within 5–600 seconds.

**Status changes**
- Any structural change to a published paper returns it to **draft**, so it must pass
  validation again. Title and description edits don't.
- **Locked:** once any non-draft room uses a paper, its questions, order, timings and
  target count can't change, and it can't return to draft. Use **Duplicate** to get an
  editable draft copy.
- Archived papers are read-only until restored.

**Preview** shows the paper exactly as a snapshot would contain it, with the answer key
highlighted for staff only.

## Competition snapshots (`PaperSnapshotService`)

`snapshot(Room)` freezes the room's published, valid paper into `competition_sessions`,
`session_questions` and `session_question_options`, in one locked transaction. The snapshot holds:
- question text, explanation and image path;
- order, effective time limit and points;
- every option and **the correct answer**.

It is idempotent: a room keeps its first snapshot, and the action is audited as
`room.snapshot_created`.

**Immutability is enforced twice**
- **Database triggers** reject any change to snapshot content or options. Only the engine's
  `opens_at` / `closes_at` / `revealed_at` columns can be updated. Deletes are rejected.
- **The models** refuse the same changes and hide `is_correct` and `image_path` from
  serialization.

So editing, archiving or deleting a bank question, or editing or duplicating a paper, never
changes a competition that has already been prepared.

**When snapshots are taken:** the service exists now, but no room publishes yet. The room
management phase must call it when a room is prepared (published). Rooms that the development
seeder published before this phase have no snapshot.
