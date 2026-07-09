# local_aigrader

A minimal Moodle plugin exposing one web service function,
`local_aigrader_set_essay_grade`, so an external AI grading system can
grade essay questions inside quiz attempts using Moodle's own grading
pipeline (no direct DB writes).

## What it does

Given a quiz `attemptid`, a question `slot`, a `grade`, and `feedback`,
the function:

1. Loads the attempt with `quiz_attempt::create()`.
2. Checks the calling user has `local/aigrader:setessaygrade` in that quiz's context.
3. Confirms the slot holds an essay question.
4. Grades it via `question_usage_by_activity::manual_grade()` — the
   question engine's own purpose-built API for manual grading actions
   (the same one Moodle's core test suite uses), which moves the
   question from "Requires grading" to "Manually graded".
5. Recalculates the attempt's `sumgrades` (this is `null` until every
   question in the attempt has a mark — a multi-essay quiz needs every
   essay graded before the total appears) and pushes the result to the
   gradebook via `$quizobj->get_grade_calculator()->recompute_final_grade()`,
   mirroring exactly what `quiz_attempt::process_submitted_actions()`
   does internally after a grading action.

## Install

1. Copy this folder to `<moodle_root>/local/aigrader`.
2. Visit *Site administration > Notifications* to run the DB upgrade
   and register the plugin.

## Enable web services (one-time site setup, if not already done)

1. *Site administration > Server > Web services > Overview* — enable
   web services, and enable the REST protocol (or whichever protocol
   your AI system will call).
2. *Site administration > Server > Web services > External services*
   — you should see **AI Essay Grader** (created by this plugin's
   `db/services.php`). Confirm it's enabled.
3. *Site administration > Server > Web services > Manage tokens* —
   create a token for a dedicated service account user (don't reuse a
   real teacher's personal token), scoped to the **AI Essay Grader**
   service.
4. Create a dedicated role for the service account so it has *only*
   the permissions this plugin needs, rather than full teacher grading
   rights:
   - *Site administration > Users > Permissions > Define roles* >
     **Add a new role**.
   - Give it context types of **System**, **Course**, and **Module**
     (all three — the role needs to be assignable both at system/course
     level for enrolment and general access, and at module level for
     the capability check itself), name it something like "AI grading
     service", and don't base it on an existing archetype.
   - Grant these capabilities (search each by name and set to **Allow**):
     - `local/aigrader:setessaygrade` — the actual grading permission.
     - `mod/quiz:view` — without this, Moodle treats the quiz as
       invisible to this user even when the course/section/module are
       all correctly set to visible. This capability is normally
       bundled automatically into the Student/Teacher archetypes, but
       a from-scratch role like this one doesn't get it for free.
     - `webservice/rest:use` — required to authenticate over the REST
       protocol at all. Also normally bundled into default roles, not
       granted automatically to a custom one.
   - Assign this role to your service account user at **both**:
     - System context (*Site administration > Users > Permissions >
       Assign system roles*), so the capability check succeeds, **and**
     - An actual course enrolment (course → *Participants* → *Enrol
       users*, picking this role) for every course containing quizzes
       this service account should grade — `require_login()` checks
       real enrolment, not just a role assignment, so a system-level
       role alone isn't enough.

This means the service account can call `local_aigrader_set_essay_grade`
and nothing else in the quiz grading workflow — it has no access to the
grading report UI, can't edit marks by hand, and isn't a full teacher.

## Example call (REST, curl)

```bash
curl -s "https://your-moodle-site/webservice/rest/server.php" \
  --data-urlencode "wstoken=YOUR_TOKEN" \
  --data-urlencode "wsfunction=local_aigrader_set_essay_grade" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "attemptid=12345" \
  --data-urlencode "slot=3" \
  --data-urlencode "grade=7.5" \
  --data-urlencode "feedback=<p>Good structure, but the conclusion needs more support.</p>" \
  --data-urlencode "feedbackformat=1"
```

Successful response:

```json
{
  "attemptid": 12345,
  "slot": 3,
  "mark": 7.5,
  "maxmark": 10,
  "sumgrades": 27.5,
  "quizgrade": 100,
  "status": "manuallygraded"
}
```

## Notes / things to double-check before production use

- **Moodle version**: you mentioned Moodle 5.3dev (build 20260624).
  5.3 is the upcoming LTS release and is still under active weekly
  development (code freeze is 24 Aug 2026), so treat anything below
  as "current as of this dev build" rather than final. A few
  version-specific points:
  - Minimum PHP for 5.3 is **8.3** (8.4 also supported) — make sure
    your dev environment matches.
  - Moodle 5.3 upgrades from 4.5, so `version.php`'s `$plugin->requires`
    is set to the 4.5 branch value (`2024100700`) above — bump this if
    you want to hard-require a later minimum.
  - This plugin was actually built and tested against your live
    5.3dev (build 20260624) instance. Along the way we found and
    worked around several real API changes versus older Moodle docs:
    `quiz_attempt` moved to the `mod_quiz\quiz_attempt` namespace; the
    core `external_*` classes moved to `core_external\`;
    `quiz_attempt::process_submitted_actions()` is for simulating a
    *student's* answer, not for submitting a manual grade —
    `question_usage_by_activity::manual_grade()` is the correct API;
    `quiz_attempt::get_question_usage()` is restricted to unit tests
    (use `question_engine::load_questions_usage_by_activity()`
    instead); and `quiz_attempt::recompute_final_grade()` is
    `protected` — call it via
    `$quizobj->get_grade_calculator()->recompute_final_grade($userid)`
    instead. All of these are already reflected in the shipped code.
- **Capability scope**: this now uses a dedicated
  `local/aigrader:setessaygrade` capability (defined in
  `db/access.php`) instead of `mod/quiz:grade`, so the AI service
  account can be granted exactly this permission and nothing else —
  it won't have access to the full teacher grading UI. You still need
  to create a role that grants this capability and assign it to the
  service account (see the setup steps above); it isn't given to any
  role by default. In testing we found the service account also needs
  `mod/quiz:view` (bundled automatically into Student/Teacher
  archetypes, but not into a from-scratch role like this one) and
  `webservice/rest:use` — without `mod/quiz:view`, Moodle treats the
  quiz as invisible to that user even when every visibility flag on
  the course/section/module is correctly set to visible; without
  `webservice/rest:use`, authentication itself is refused. Both are
  called out in the setup steps above.
- **Concurrent grading**: if a human teacher and the AI service could
  grade the same attempt at the same time, consider adding a lock or
  "last graded by" check to avoid races — this skeleton doesn't handle
  that.
- **Batch grading**: this function grades one slot at a time. If you
  need to grade many attempts/slots per call, wrap this in a second
  function that loops over an array of `{attemptid, slot, grade,
  feedback}` and returns an array of results, still inside one DB
  transaction per attempt.
- **`sumgrades` stays `null` until every question is graded**: this is
  expected Moodle behaviour, not a bug — `question_usage_by_activity::get_total_mark()`
  returns `null` if any question in the attempt is still ungraded. On
  a quiz with multiple essay questions, the attempt's overall grade
  and gradebook entry won't appear until all of them have been graded
  (by this web service, a human, or a mix of both).
- **Regrade side effects**: this plugin calls
  `$quizobj->get_grade_calculator()->recompute_final_grade($userid)`
  directly (the same call `quiz_attempt::process_submitted_actions()`
  makes internally after a grading action), so you should *not* also
  manually call gradebook-update functions elsewhere — doing both
  would be redundant.
