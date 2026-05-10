# ophelios-studio/skills

Empirical agent skills for AI coding assistants — Claude Code, Cursor, Codex,
Cline, Gemini CLI, and any other tool implementing the
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
npx skills add ophelios-studio/skills --skill axl
npx skills add ophelios-studio/skills --skill axl-pubsub
npx skills add ophelios-studio/skills --skill kintsugi
npx skills add ophelios-studio/skills --skill leaf
npx skills add ophelios-studio/skills --skill zephyrus
```

The CLI installs to `~/.agents/skills/<name>/` and symlinks into your
agent's skill directory (e.g. `~/.claude/skills/`).

## Skills

| Skill        | What it covers                                                     |
|--------------|--------------------------------------------------------------------|
| `0g`         | 0G Chain (Galileo, 16602), Storage, Compute — empirical patterns   |
| `axl`        | Gensyn AXL HTTP API — protocol primitives, library-agnostic        |
| `axl-pubsub` | The `axl-pubsub` gossip library on top of AXL — when and how       |
| `kintsugi`   | EIP-7702 wallet rescue, atomic batches, custom-call composition    |
| `leaf`       | Zephyrus Leaf static-site generator                                |
| `zephyrus`   | Zephyrus PHP framework                                             |

## Repo layout

```
skills/<name>/SKILL.md      # the skill itself (frontmatter + body)
examples/<name>/            # minimal runnable scripts a dev can verify
```

## Philosophy

Skills written from docs alone go stale fast. These skills capture what
actually happens when you run real code against real testnets and real
infrastructure — empirical reality first, official documentation second.

Every gotcha in every skill is reproducible: cite the file:line of the
production code that taught us, link the live transaction or test run
that proves it.

## License

MIT.
