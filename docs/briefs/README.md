# Agent briefs

Each file here is a self-contained assignment. A brief assumes the agent has
this repository checked out and nothing else — it names what to read, what to
produce, and what "done" means, so it can be handed over without a conversation
attached.

## Handing a brief to an agent

In a Claude Code session in this repository:

```
Read CLAUDE.md, then carry out docs/briefs/brief-02-test-harness.md in full.
```

`CLAUDE.md` loads automatically, but naming it makes the dependency explicit and
survives being pasted into a fresh session. Each brief lists its own required
reading in its **Inputs** section.

## Suggested order

The briefs are ordered by what unblocks the most downstream work, not by what is
most interesting.

| # | Brief | Why this order | Depends on |
| --- | --- | --- | --- |
| 01 | `brief-01-functional-spec.md` | Everything else is guessing until v1 behaviour is written down and its open questions are closed. | — |
| 02 | `brief-02-test-harness.md` | Nothing in the repo has ever been executed. Until it can be, no claim about it is verifiable. | — |
| 03 | `brief-03-social-architecture.md` | The largest unbuilt surface, and the one where a wrong schema is most expensive to undo. | 01 |
| 04 | `brief-04-bridge-contract.md` | Defines the hook surface both plugins must honour; cheaper to fix before either side is built against it. | 01, 03 |
| 05 | `brief-05-lms-hardening.md` | Closes the known gaps in the existing scaffold against the now-written spec. | 01, 02 |

01 and 02 are independent and can run in parallel. 03 should not start before 01
is settled, because its schema follows from the feature set.

## Writing a new brief

Keep the shape:

- **Objective** — one paragraph. What decision or artefact this produces.
- **Inputs** — exact files to read first.
- **Constraints** — what is already decided and must not be relitigated.
- **Deliverables** — exact file paths to create, with what belongs in each.
- **Definition of done** — checkable statements, not aspirations.
- **Out of scope** — the things an agent will otherwise helpfully do anyway.

A brief that does not name file paths produces a conversation instead of an
artefact.
