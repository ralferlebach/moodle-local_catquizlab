@local @local_catquizlab
Feature: Reaching the CAT experiment suite management page
  In order to prepare and run CAT experiments
  As a manager
  I need to reach the suite from the navbar and from the reports section

  Background:
    Given I log in as "admin"

  @javascript
  Scenario: The navbar button opens the management page
    When I follow "CATQUIZ-Lab"
    Then I should see "CAT experiment suite"
    # The landing page is the experiment plan, so what it shows is experiments.
    And I should see "Experiments"

  Scenario: The management page is listed under site administration reports
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "CAT experiment suite"
    And I should see "No experiments defined yet."

  Scenario: Setup and operations are reachable from the plugin's own page
    Given I log in as "admin"
    And I navigate to "Reports > CAT experiment suite" in site administration
    # No detour through the Moodle administration: the tab is on the page the
    # person is already on.
    When I follow "1. Preparation"
    Then I should see "Setup and readiness"
    # The action now sits on the step it fixes, not in a card repeating it.
    And I should see "3. Worker access to Moodle"
    And I should see "System"
    And I should see "CAT engine"
    # Its eleven checks are steps of the process now, not a card beside it.
    And I should see "Token stored in the plugin setting"
    # Was a heading of the system card; the pipeline is step 5 of the process.
    And I should see "5. Pipeline"
    # The queue, the workers and recovery are step 3: watching a run happen is
    # not the same question as whether the installation can run anything.
    And I follow "3. Progress"
    And I should see "Attempt queue"
    And I should see "Start workers"
    And I should see "Release orphaned claims"
