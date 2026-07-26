# Product Requirements: Funnel-Linked Opportunities CRM

## Overview

OpenFunnels Opportunities turns captured contacts into trackable sales deals. It adds the operational layer between lead capture and revenue without requiring a separate CRM.

The feature follows the common opportunity model used by funnel-oriented CRM products while preserving OpenFunnels' self-hosted, single-owner architecture.

## User Outcomes

Users can:

1. Create multiple sales pipelines with ordered stages and forecast probabilities.
2. Promote an existing contact into an opportunity with an expected value and close date.
3. Move opportunities between stages on a Kanban board.
4. Mark deals open, won, or lost while retaining their activity history.
5. Enable individual funnels to create opportunities automatically at a chosen stage.
6. Review a contact's opportunities from the contact profile.

## Core Behavior

### Pipelines And Stages

- Pipelines are owned by one user and have one currency.
- New pipelines start with New Lead, Contacted, Qualified, and Proposal stages.
- Stage probabilities power weighted pipeline forecasts.
- A populated pipeline cannot be deleted.
- Deleting a populated stage requires moving its opportunities to another stage.

### Opportunities

- Every opportunity belongs to one contact, pipeline, and stage.
- An opportunity can optionally retain its source funnel.
- Supported outcomes are `open`, `won`, and `lost`.
- Won and lost opportunities receive a closure timestamp; reopening clears it.
- Creation, stage, outcome, and value changes are recorded in an activity timeline.

### Funnel Automation

- Automation is disabled by default and configured independently for each funnel.
- Configuration selects a destination pipeline, starting stage, and default value.
- Repeated submissions reuse the same open opportunity for the contact, funnel, and pipeline.
- A later submission can create a new opportunity after the previous one is closed.
- Missing or stale CRM configuration never prevents lead capture.

## Current Boundaries

- OpenFunnels currently has one application user per account, so opportunity assignment and team permissions are deferred.
- Tasks, conversations, appointments, workflow builders, custom opportunity fields, and list view are future CRM increments.
- Existing contacts are not automatically promoted during migration.

## Competitive Research

- HighLevel describes opportunities as contact-linked potential deals with pipeline stage, value, status, and context: <https://help.gohighlevel.com/support/solutions/articles/155000001983-understanding-opportunities-in-highlevel>
- HighLevel pipelines support configurable stages and visual progression: <https://help.gohighlevel.com/support/solutions/articles/155000001985-step-by-step-guide-creating-pipelines>
- ClickFunnels provides a visual Opportunities CRM with pipelines, stages, deal values, close dates, and contact links: <https://support.myclickfunnels.com/docs/getting-started-with-opprtunities>
- ClickFunnels workflows can create or move opportunities from funnel activity: <https://support.myclickfunnels.com/docs/create-move-opportunity-step-manage-opportunities-in-pipelines>
