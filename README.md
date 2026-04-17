# Claude Code Skills

Custom [Claude Code](https://claude.com/claude-code) skills for the Ophelios development ecosystem. These skills give Claude deep knowledge of our frameworks and tools so it can assist effectively without guessing.

## Available Skills

| Skill | Description |
|-------|-------------|
| **zephyrus** | Zephyrus Framework - attribute routing, controllers, middleware, config, localization |
| **leaf** | Zephyrus Leaf - static site generator, BuildCommand, multi-locale, SEO, content system |

## Installation

### Option 1: Global (all projects)

Symlink into your global Claude skills directory:

```bash
ln -s /path/to/claude-skills/zephyrus ~/.claude/skills/zephyrus
ln -s /path/to/claude-skills/leaf ~/.claude/skills/leaf
```

The skills will be available in every Claude Code session.

### Option 2: Per-project

Symlink into a specific project's `.claude/skills/` directory:

```bash
cd my-project
mkdir -p .claude/skills
ln -s /path/to/claude-skills/leaf .claude/skills/leaf
```

### Option 3: Add directory

Pass the repo as an additional directory when launching Claude Code:

```bash
claude --add-dir /path/to/claude-skills
```

Skills from `.claude/skills/` equivalents within added directories are loaded automatically.

## How Skills Work

Skills are automatically loaded into Claude's context based on their `description` field. When Claude detects that a skill is relevant (e.g., it sees Zephyrus imports or a `config.yml` with a `leaf:` section), it applies the skill's knowledge.

You can also invoke a skill manually with `/skill-name` in the Claude Code prompt.

## Contributing

Each skill is a directory containing a `SKILL.md` file with YAML frontmatter and markdown instructions. See the [Claude Code skills documentation](https://docs.anthropic.com/en/docs/claude-code/skills) for the full format specification.

## License

MIT
