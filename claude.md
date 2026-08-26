# Programming Manifesto

## Purpose

Build useful software by discovering the real problem, modeling it explicitly, and introducing only the complexity the current requirements can justify. The methodology should be executable by humans and agents, not merely descriptive.

## Engineering Constitution

- Minimize accidental complexity. Treat every abstraction, dependency, service, and layer as a cost.
- Prefer boring, explicit architecture whose behavior can be traced directly.
- Build for demonstrated requirements. No speculative infrastructure or premature generalization.
- Introduce contracts and interfaces only at genuine substitution or integration boundaries.
- Prefer composition over inheritance and small capabilities over broad frameworks or objects.
- Keep business rules separate from delivery and infrastructure concerns.
- Start with server-rendered HTML. Add JavaScript as progressive enhancement.
- Use semantic HTML and accessible interaction patterns from the first implementation.
- Keep repository documentation aligned with current reality: architecture, workflows, decisions, constraints, and operating instructions.
- Optimize for changeability, comprehensibility, and deletion—not architectural novelty.

## Stage 1: Product Discovery and Planning

Before implementation:

1. Define the problem, project vision, value proposition, and success criteria.
2. Discover users, stakeholders, constraints, risks, unknowns, and business-model assumptions.
3. Model workflows and state transitions explicitly.
4. Create the feature inventory and assign each feature to MVP or a later stage.
5. Seed the domain glossary.
6. Maintain:
   - a decision register: decision, rationale, alternatives;
   - an open-questions register;
   - an assumptions register.

Implementation begins when the problem, first useful outcome, essential workflow, constraints, and acceptance criteria are sufficiently explicit.

## Structured Project Memory

Maintain durable, current, machine-readable project memory containing:

- facts;
- assumptions;
- decisions and rationale;
- open questions;
- constraints;
- glossary and domain language;
- features and stage assignments;
- workflows and state models;
- content opportunities.

Agents consume this memory instead of reconstructing project state from raw chat history. When reality changes, update the memory and repository documentation in the same unit of work.

## Agent-Orchestration Model

A skill is a callable workflow, not a prompt. Every skill declares:

- required inputs;
- outputs and artifacts;
- dependencies and readiness conditions;
- whether it can run in parallel;
- required human decisions or approvals;
- completion and exit criteria.

The orchestrator selects ready work, delegates bounded tasks, preserves dependency order, and returns durable artifacts to project memory. Agents must expose uncertainty; they must not silently convert assumptions into facts.

## Content Incubator

Observe the project continuously: discovery, architecture, implementation, decisions, failures, and corrections. Capture reusable content opportunities with:

- format: post, short video, or long-form video;
- user problem;
- concept;
- technology;
- project or build context;
- audience and skill level;
- why it matters—the prospective hook.

Store these as a connected content graph, not isolated ideas. Content capture must not interrupt delivery.

## Operating Rule

For every increment:

**Discover → model → constrain → decide → implement the smallest coherent capability → verify → document current state → capture reusable learning.**

A change is complete only when its behavior is verified, its relevant decisions and project memory are current, and no unjustified complexity remains.

**The goal is not merely to describe good development practice. The goal is to make the Manifesto an executable development methodology.**
