# Reference run of the CLARA-like benchmark (#170 DoD 2)

Acceptance run **45038234242** on **e605b2e78797efae7aed2e190efea5b10b0a3ac5** (CatQuizLab 0.7.78, 22.11.2026).
These are its original GitHub artifact ZIPs, byte for byte, and the engine pins of that commit.

| file | artifact id | SHA-256 |
|---|---|---|
| `catquizlab-acceptance-clara-bench-after.zip` | 14761403605 | `1c7a76ad9db019427869de6a978b352cceb9870a98f746368888386813803353` |
| `catquizlab-acceptance-clara-bench-before.zip` | 14763292823 | `80dc76e4578513708b2d7867be7877b2e361243267ae730bb715e5750cf39dcd` |
| `catquizlab-acceptance-clara-report.zip` | 14762403923 | `5787aaca35870633eaff4beb74ff19b63234c39d8c099bfe3c6a4ef70ea88c18` |
| `pins/engine-pins.env` | `.github/engine-pins.env` at e605b2e7 | `b3e2ac22c31da2a5391bda8fcfbf9327bacbb90eb7286cc0206dfe2b2caba09c` |
| `pins/engine-pins-previous.env` | `.github/engine-pins-previous.env` at e605b2e7 | `5c45115d71caabf5bb085d9207639bcd681ec3e0e9f8444a80cb3e83c2befe5a` |

**Integrity:** the digests above are written into `cli/clara_reference.py` (`REFERENCE`), not into a file next to
the data. A changed archive therefore fails the replay unless the script is changed too, and that change is visible
in the review.

**Rules:**
- Nothing here is edited, repacked or "modernised": not the ZIPs and not `host.json` inside them.
- The run predates the resume step, so it is judged with profile `0.7.78`, the matrix of that run. `clara_bench.py`
  accepts this profile for this commit only.
- The two legs ran on two GitHub-hosted runners. They are not one machine and are not reported as one.
- The historical CLARA runs #87/#88 stay `WAIVED`, in the original report and in the replay.

**Replay** (CI job "Issue 170 — benchmark gate: negative matrix, reference replay", Release CI and CI):

    python3 cli/clara_reference.py --fixture tests/fixtures/clara-reference-e605b2e7 --out OUT
    python3 cli/clara_reference.py --self-test --fixture tests/fixtures/clara-reference-e605b2e7 --out OUT

The first command must end with 0:
- every digest matches;
- `host.json` of both legs names this commit and run;
- `clara_bench.py --profile 0.7.78` passes, and so does `--verify-report`;
- every point has its original status, and GATE-170 is PASS.

The second command must end with 0: every copy of the fixture with one thing wrong must fail the replay. The cases are
a ZIP missing or changed, a file removed inside a leg, a pin changed, the original waiver turned PASS, another commit,
and not a ZIP.
