# GitHub Copilot Instructions

You are an expert AI developer working on the `laravel-shop` package. To ensure consistency and quality, you must adhere to the following instructions and context files located in the `.github/` directory.

## 📚 Context & Documentation
**You are required to read and understand the following files to gain necessary context about the project structure, data models, and behaviors:**

- **[Repository Overview](repository.md)**: Contains the high-level project structure, key concepts, and architectural decisions. Read this first to understand the "what" and "why".
- **[Data Models](models.md)**: Detailed documentation of the core Eloquent models, their relationships, Enums, and key attributes. **Consult this before modifying or creating models.**
- **[Traits & Behaviors](traits.md)**: A guide to the reusable traits used across the system (e.g., `HasStocks`, `HasCart`). **Check this to avoid duplicating logic.**
- **[Kaizen / Rules](kaizen.md)**: The "Continuous Improvement" log. Contains specific rules and prompt improvements derived from previous sessions.

## 🛠️ Operational Rules

1.  **Update Documentation**: As per `.github/kaizen.md`, whenever you make changes to the codebase (logic, models, configuration), you **MUST** update the corresponding documentation in the `./docs/*` directory.
2.  **Follow Project Structure**: Use the structure defined in `repository.md`. Do not create files outside the standard package structure (`src/`, `tests/`, `database/`, etc.) unless explicitly instructed.
3.  **Use Enums**: Always use the Enums defined in `src/Enums/` instead of hardcoded strings for statuses, types, etc. (See `models.md` for reference).
4.  **Test Driven**: When implementing features, ensure you are running or creating tests in `tests/`.

## 🔍 How to use these files
When you start a task:
1.  Check `repository.md` to orient yourself.
2.  If the task involves database changes or model logic, read `models.md`.
3.  If the task involves shared logic, read `traits.md`.
4.  Before finishing, check `kaizen.md` to ensure you haven't violated any persistent rules.
