# Claude Code Skills

Custom [Claude Code](https://claude.com/claude-code) skills for the Ophelios development ecosystem. These skills give Claude deep knowledge of our frameworks and tools so it can assist effectively without guessing.

## Available Skills

| Skill | Description |
|-------|-------------|
| **zephyrus** | Zephyrus Framework - attribute routing, controllers, middleware, config, localization |
| **leaf** | Zephyrus Leaf - static site generator, BuildCommand, multi-locale, SEO, content system |

## Installation

Clone directly into your Claude Code skills directory:

```bash
git clone https://github.com/ophelios-studio/claude-skills.git ~/.claude/skills/ophelios
```

All skills are available immediately in every Claude Code session. To update:

```bash
cd ~/.claude/skills/ophelios && git pull
```

### Install specific skills only

If you only want certain skills, clone the repo anywhere and symlink:

```bash
git clone https://github.com/ophelios-studio/claude-skills.git ~/claude-skills
ln -s ~/claude-skills/leaf ~/.claude/skills/leaf
ln -s ~/claude-skills/zephyrus ~/.claude/skills/zephyrus
```

### Per-project

Add skills to a specific project instead of globally:

```bash
cd my-project
mkdir -p .claude/skills
ln -s /path/to/claude-skills/leaf .claude/skills/leaf
```

## How Skills Work

Skills are automatically loaded into Claude's context based on their `description` field. When Claude detects that a skill is relevant (e.g., it sees Zephyrus imports or a `config.yml` with a `leaf:` section), it applies the skill's knowledge.

You can also invoke a skill manually with `/skill-name` in the Claude Code prompt.

## Contributing

Each skill is a directory containing a `SKILL.md` file with YAML frontmatter and markdown instructions. See the [Claude Code skills documentation](https://docs.anthropic.com/en/docs/claude-code/skills) for the full format specification.

## License

MIT
