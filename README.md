# ophelios-studio/skills

Empirical agent skills for AI coding assistants (Claude Code, Cursor, Codex,
Cline, Gemini CLI) and any other tool implementing the
[agentskills.io](https://agentskills.io) spec.

Every skill is **derived from hands-on integration work**, not just docs.
Every gotcha has a reproducible repro path documented in the skill itself,
plus a minimal runnable example under `examples/<skill>/`.

## Install

```bash
# all skills
npx skills add ophelios-studio/skills

# one specific skill
npx skills add ophelios-studio/skills --skill 0g
npx skills add ophelios-studio/skills --skill agreely
npx skills add ophelios-studio/skills --skill agreely-cookies
npx skills add ophelios-studio/skills --skill axl
npx skills add ophelios-studio/skills --skill axl-pubsub
npx skills add ophelios-studio/skills --skill codequill
npx skills add ophelios-studio/skills --skill immunity
npx skills add ophelios-studio/skills --skill kintsugi
npx skills add ophelios-studio/skills --skill leaf
npx skills add ophelios-studio/skills --skill zephyrus
```

Without `-g`, skills land in the current project: `./.agents/skills/<name>/`,
a symlink in each detected agent's directory (`./.claude/skills/<name>` for
Claude Code) and a `skills-lock.json`. With `-g`, the same layout goes under
your home: `~/.agents/skills/<name>/`, symlinked into `~/.claude/skills/<name>`
and every other detected agent; when the CLI detects no agent, it links into
every agent it supports. To install for Claude Code alone, add `-a claude-code`:
the skill is then copied straight into `~/.claude/skills/<name>/`, with no
`.agents` directory.

```bash
npx skills add ophelios-studio/skills --skill zephyrus -g -a claude-code
```

## Skills

| Skill             | What it covers                                                                                          |
|-------------------|---------------------------------------------------------------------------------------------------------|
| `0g`              | 0G Chain (Galileo, 16602), Storage, Compute: empirical patterns                                         |
| `agreely`         | Agreely PHP SDK + CLI: one-call Law 25 consent gate + signed receipts                                   |
| `agreely-cookies` | Agreely cookie banner (`cdn.agreely.ca/e/v1`) - one tag, zero-click enforcement                         |
| `axl`             | Gensyn AXL HTTP API: protocol primitives, library-agnostic                                              |
| `axl-pubsub`      | The `axl-pubsub` gossip library on top of AXL: when and how                                             |
| `codequill`       | CodeQuill CLI: on-chain source-evidence (claims, snapshots, …)                                          |
| `immunity`        | Immunity Protocol SDK (`@immunity-protocol/sdk`): threat antibodies checked before an agent transaction |
| `kintsugi`        | EIP-7702 wallet rescue, atomic batches, custom-call composition                                         |
| `leaf`            | Zephyrus Leaf static-site generator                                                                     |
| `zephyrus`        | Zephyrus PHP framework                                                                                  |

## Repo layout

```
skills/<name>/SKILL.md      # the skill itself (frontmatter + body)
examples/<name>/            # minimal runnable scripts a dev can verify
```

## Philosophy

Skills written from docs alone go stale fast. These skills capture what
actually happens when you run real code against real testnets and real
infrastructure: empirical reality first, official documentation second.

Every gotcha in every skill is reproducible: cite the file:line of the
production code that taught us, link the live transaction or test run
that proves it.

## License

MIT.
