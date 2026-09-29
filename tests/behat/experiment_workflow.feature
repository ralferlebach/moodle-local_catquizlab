@local @local_catquizlab
Feature: Defining and running CAT experiments from the web interface
  In order to run the experimental design without the command line
  As a manager
  I need to create, validate, expand and inspect experiments in the browser

  Background:
    Given I log in as "admin"

  Scenario: The empty registry offers a way forward instead of a dead end
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "No experiments defined yet."
    And I should see "New experiment"

  Scenario: Creating an experiment through the editor
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    Then I should see "New experiment"
    And I should see "Master seed"
    When I set the following fields to these values:
      | Name                        | Behat baseline |
      | Persons per run             | 5              |
      | Minimum items (global)      | 10             |
      | Maximum items (global)      | 15             |
      | SE lower bound              | 0.35           |
      | SE upper bound              | 0.75           |
    And I press "Save experiment"
    Then I should see "Experiment saved."
    And I should see "Edit experiment"

  Scenario: A contradictory budget is refused with a field-level message
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I set the following fields to these values:
      | Name                   | Behat broken budget |
      | Minimum items (global) | 40                  |
      | Maximum items (global) | 10                  |
    And I press "Save experiment"
    Then I should see "minitems must not exceed maxitems"

  Scenario: The sweep preview reports the runs before anything is created
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat preview |
      | replications | 2             |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat preview"
    Then I should see "Sweep preview"
    And I should see "Resulting runs"
    And I should see "Create sweep"

  Scenario: Creating a sweep from the editor produces runs
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat sweep |
      | replications | 2           |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat sweep"
    And I press "Create sweep"
    Then I should see "Sweep created with"

  Scenario: The experiment overview shows publication labels
    Given the following "local_catquizlab > experiment" exists:
      | name     | Behat labelled |
      | strategy | lowestsub      |
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "Infer lowest skill gap"

  Scenario: Runs can be filtered and opened
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat runs |
      | replications | 2          |
    And the experiment "Behat runs" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    # The runs live on step 3. The plan step no longer lists them, so there is
    # no "All runs" link out of it — the tab is the way there.
    When I follow "3. Progress"
    Then I should see "Runs"
    And I should see "Behat runs"
    And I should see "Any status"

  Scenario: A run detail page exposes the reproducibility manifest
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat manifest |
      | replications | 1              |
    And the experiment "Behat manifest" has been expanded into runs
    When I open the first run of "Behat manifest"
    Then I should see "Reproducibility manifest"
    And I should see "Cell key"

  Scenario: The import page previews a file before storing it
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Import settings (JSON)"
    Then I should see "Import experiment settings"
    And I should see "Definition file"
    And I should see "If the name already exists"

  Scenario: The landing page shows the overview and the way in
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "Overview"
    And I should see "Experiments"
    And I should see "Active runs"
    And I should see "New experiment"
    And I should see "Building blocks"

  Scenario: An item pool can be saved as a reusable block and reused
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat blocks |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Building blocks"
    Then I should see "Reusable building blocks"
    And I should see "No building blocks yet."
    When I set the field "fromexperiment" to "Behat blocks"
    And I press "Save as a building block"
    Then I should see "as a reusable building block"
    And I should see "Item pool"

  Scenario: The overview counts failed runs separately
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat counted |
    And the experiment "Behat counted" has been expanded into runs
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "Completed runs"
    And I should see "Failed runs"

  Scenario: The editor shows section navigation and a validation panel
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat editor |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat editor"
    Then I should see "Validation"
    And I should see "The definition is valid."
    And I should see "Errors"
    And I should see "Sweep preview"
    And I should see "Experiment ID"
    And I should see "Master seed"

  Scenario: An experiment with runs says so instead of silently refusing
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat locked |
    And the experiment "Behat locked" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat locked"
    Then I should see "cannot be changed here"

  Scenario: The results page offers the filter bar and the tabs
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat results |
    And the experiment "Behat results" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "4. Results"
    Then I should see "Results"
    And I should see "Overview"
    And I should see "Global metrics"
    And I should see "Robustness"
    And I should see "Test flow"
    And I should see "Any strategy"
    # The runs of this experiment exist but are all drafts, so the page says
    # that nothing has been started rather than blaming the filter.
    And I should see "No run has been started yet"
    And I should see "draft"

  Scenario: The local diagnostics tabs are reachable and name their subject
    Given the following "local_catquizlab > experiment" exists:
      | name     | Behat local |
      | strategy | lowestsub   |
    And the experiment "Behat local" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "4. Results"
    When I follow "Subscales"
    Then I should see "Local diagnostic performance"
    And I should see "No subscale-level data under this filter."
    When I follow "Deficit detection"
    Then I should see "No subscale-level data under this filter."

  Scenario: The robustness tab explains its reference even with nothing to compare
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat robustness |
    And the experiment "Behat robustness" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "4. Results"
    When I follow "Robustness"
    Then I should see "Robustness against pool disturbances"
    And I should see "measured against the ideal pool"

  Scenario: The test-flow tab explains the feasibility arithmetic
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat flow |
    And the experiment "Behat flow" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "4. Results"
    When I follow "Test flow"
    Then I should see "Test flow and feasibility"
    And I should see "I = 1 / SE"

  Scenario: Raw data and export name their levels and their provenance
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat export |
    And the experiment "Behat export" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "4. Results"
    When I follow "Raw data"
    Then I should see "Raw data"
    And I should see "Data level"
    When I follow "Export"
    Then I should see "Export the current selection"
    And I should see "Attempt level"
    And I should see "Item level"
    And I should see "What the file will say about itself"

  Scenario: Preparation says when no experiment course is configured
    When I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "1. Preparation"
    Then I should see "Choose an experiment course"

  Scenario: A configured experiment course is shown and linked
    Given the following "courses" exist:
      | fullname        | shortname |
      | CATLab Studies  | catlab    |
    And the course "catlab" is the experiment course
    When I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "1. Preparation"
    Then I should see "Experiment course:"
    And I should see "CATLab Studies"

  Scenario: The editor offers every sweep factor of the design
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat sweep factors |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat sweep factors"
    Then I should see "Vary strategy"
    And I should see "Vary the IRT model"
    And I should see "Vary the global item budget"
    And I should see "Vary the subscale item budget"
    And I should see "Vary the SE window"
    And I should see "Vary the disturbance strength"

  @javascript
  Scenario: The settings link from preparation opens without a section error
    # The experiment course moved to preparation, where it is created: on the
    # plan step it was one of three things that made a plan unreadable as a plan.
    Given I log in as "admin"
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "1. Preparation"
    When I follow "Choose an experiment course"
    Then I should not see "Section error"
    And I should see "CAT experiment suite"

  Scenario: A created sweep is not presented as an executed experiment
    Given the following "local_catquizlab > experiment" exists:
      | name | Lifecycle demo |
    And I log in as "admin"
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Lifecycle demo"
    And I press "Create sweep"
    Then I should not see "Executed"
    And I should see "Runs created, not started"

  Scenario: Draft runs can be started from the web interface
    Given the following "local_catquizlab > experiment" exists:
      | name | Startable |
    And I log in as "admin"
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "Startable"
    And I press "Create sweep"
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "Startable"

  Scenario: Results explain that nothing has been started yet
    Given the following "local_catquizlab > experiment" exists:
      | name | Nothing played |
    And I log in as "admin"
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "Nothing played"
    And I press "Create sweep"
    When I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "4. Results"
    Then I should see "No run has been started yet"

  @javascript
  Scenario: Only strategies the engine can play are offered, in both lists
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    # Opened first: a collapsed section's options have no visible text, and a
    # "does not contain" check on invisible options passes for nothing.
    And I expand all fieldsets
    # Checked by value as well as by text, so that neither can pass alone.
    Then the "CAT strategy" select box should contain "allsubs"
    And the "CAT strategy" select box should not contain "balanced"
    And the "CAT strategy" select box should not contain "pilot"
    And the "Vary strategy" select box should contain "allsubs"
    And the "Vary strategy" select box should contain "Infer all subscales (allsubs)"
    And the "Vary strategy" select box should not contain "balanced"
    And the "Vary strategy" select box should not contain "pilot"
    And the "Vary strategy" select box should not contain "Balanced content control (balanced)"

  @javascript
  Scenario: A strategy without subscales needs no subscale budget
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I set the following fields to these values:
      | Name                   | Behat fastest only                       |
      | CAT strategy           | CAT (fastest)                            |
      | Minimum items (global) | 15                                       |
      | Maximum items (global) | 35                                       |
    # The subscale budgets mean nothing to "fastest": they are switched off,
    # and saving must not demand them.
    Then the "Minimum items per subscale" "field" should be disabled
    And the "Maximum items per subscale" "field" should be disabled
    And I press "Save experiment"
    Then I should see "Experiment saved."
    And I should not see "must be a positive integer"

  @javascript
  Scenario: Per-strategy budgets are labelled and follow the chosen strategies
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I set the field "CAT strategy" to "Infer all subscales (allsubs)"
    And I expand all fieldsets
    Then I should see "Questions per sitting"
    And I should see "per subscale"
    # The chosen strategy's row is active, with subscale fields; a strategy not
    # in play has its row switched off; "fastest" never has subscale fields.
    And the "perstrategy_allsubs_globalmax" "field" should be enabled
    And the "perstrategy_allsubs_subscalemax" "field" should be enabled
    And the "perstrategy_fastest_globalmax" "field" should be disabled
    And the "perstrategy_fastest_subscalemax" "field" should be disabled
    # Choosing "fastest" as well switches its row on — but never its subscale fields.
    When I set the field "Vary strategy" to "CAT (fastest),Infer all subscales (allsubs)"
    Then the "perstrategy_fastest_globalmax" "field" should be enabled
    And the "perstrategy_fastest_subscalemax" "field" should be disabled

  @javascript
  Scenario: Parameters a strategy does not use say so
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I expand all fieldsets
    And I set the field "CAT strategy" to "Classical test (classic)"
    Then the "Minimum items per subscale" "field" should be disabled
    And the "SE lower bound" "field" should be disabled
    And the "Include pilot questions" "field" should be disabled
    And I should see "Not applicable: none of the chosen strategies works with subscales."
    And I should see "Not applicable: none of the chosen strategies stops by standard error"
    And I should see "Not applicable: none of the chosen strategies can include pilot questions."
    When I set the field "CAT strategy" to "Infer all subscales (allsubs)"
    Then the "Minimum items per subscale" "field" should be enabled
    And the "SE lower bound" "field" should be enabled
    And I should not see "Not applicable: none of the chosen strategies works with subscales."
    And I should not see "Not applicable: none of the chosen strategies stops by standard error"

  Scenario: The results report only the stop rules each strategy actually had
    Given the following "local_catquizlab > experiment" exists:
      | name            | Behat stop rules         |
      | sweepstrategies | fastest,classic,allsubs  |
    And the experiment "Behat stop rules" has been expanded into runs
    And the runs of "Behat stop rules" have 3 collected sittings each
    When I open the results of "Behat stop rules"
    Then I should see "Stop rules in force"
    # The adaptive strategies stop at the shared maximum; the classical test
    # plays every item unless given its own maximum (#104).
    And I should see "no maximum number of questions" in the "[data-strategy='classic']" "css_element"
    And I should see "stops after at most" in the "[data-strategy='fastest']" "css_element"
    # The classical test has no precision target and no subscales: neither is listed.
    And I should not see "standard error" in the "[data-strategy='classic']" "css_element"
    And I should not see "per subscale" in the "[data-strategy='classic']" "css_element"
    # "fastest" stops by precision but does not count by subscale.
    And I should see "standard error" in the "[data-strategy='fastest']" "css_element"
    And I should not see "per subscale" in the "[data-strategy='fastest']" "css_element"
    # "allsubs" has both.
    And I should see "standard error" in the "[data-strategy='allsubs']" "css_element"
    And I should see "per subscale" in the "[data-strategy='allsubs']" "css_element"

  Scenario: An experiment with runs is read-only and offers a copy to change
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat frozen |
    And the experiment "Behat frozen" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat frozen"
    Then I should see "cannot be changed here"
    And "Save experiment" "button" should not exist
    # Frozen fields are shown read-only, not removed: visible, not editable.
    And the "name" "field" should be disabled
    When I press "Duplicate and change settings"
    Then "Save experiment" "button" should exist
    And the "name" "field" should be enabled

  Scenario: A failed sitting shows its reason, its artefacts and a way to download them
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat artefacts |
    And the experiment "Behat artefacts" has been expanded into runs
    And the first run of "Behat artefacts" has a failed sitting with artefacts
    When I open the first run of "Behat artefacts"
    # The normalised reason, as a filter and on the history line.
    Then I should see "Browser: page replaced during a read (1)" in the "[data-region='catquizlab-reasons']" "css_element"
    And "[data-reason='execution_context_destroyed']" "css_element" should exist
    # Which artefacts exist and when they were written.
    And I should see "Artefacts of try 1: dom.html, screenshot-last.jpg" in the "[data-region='catquizlab-artefacts']" "css_element"
    # The downloads, for the run, its failed sittings, and the one sitting.
    And "Download debug ZIP (run)" "button" should exist
    And "Download debug ZIP (failed sittings)" "button" should exist
    And "Download debug ZIP" "button" should exist
    When I follow "Browser: page replaced during a read (1)"
    Then I should see "Execution context was destroyed"

  Scenario: Without the debug capability there is no download of debug artefacts
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | nodebug  | No        | Debug    | nodebug@example.com |
    And the following "role assigns" exist:
      | user    | role    | contextlevel | reference |
      | nodebug | manager | System       |           |
    And the following "permission overrides" exist:
      | capability              | permission | role    | contextlevel | reference |
      | local/catquizlab:debug  | Prohibit   | manager | System       |           |
    And the following "local_catquizlab > experiment" exists:
      | name | Behat no debug |
    And the experiment "Behat no debug" has been expanded into runs
    And the first run of "Behat no debug" has a failed sitting with artefacts
    And I log out
    And I log in as "nodebug"
    When I open the first run of "Behat no debug"
    # The sitting and its reason are there; the screenshots and page snapshots are not to be had.
    Then I should see "Execution context was destroyed"
    And "Download debug ZIP (run)" "button" should not exist
    And "Download debug ZIP (failed sittings)" "button" should not exist
    And "Download debug ZIP" "button" should not exist

  @javascript
  Scenario: The simulated abilities are set explicitly, and an unbounded distribution is warned about
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I expand all fieldsets
    Then the field "Distribution of simulated abilities" matches value "Truncated normal"
    And the field "Mean (μ)" matches value "0"
    And the field "Standard deviation (σ)" matches value "1"
    And the field "Ability lower bound" matches value "-3"
    And the field "Ability upper bound" matches value "3"
    # The standard deviation means nothing for a uniform distribution.
    When I set the field "Distribution of simulated abilities" to "Uniform"
    Then "Standard deviation (σ)" "field" should not be visible

  Scenario: An experiment from before shows what it really draws, and says how much falls outside
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat legacy abilities |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat legacy abilities"
    Then I should see "About 13.4 % of simulated abilities will lie outside [-3.00, 3.00]"

  Scenario: A single test in detail shows its standard error, test information and why it ended
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat single test |
    And the experiment "Behat single test" has been expanded into runs
    And the first run of "Behat single test" has a collected sitting with a full trace
    When I open the test flow of "Behat single test"
    # The head: how the test ended, as words and as a code.
    Then I should see "Test finished because: Maximum number of questions reached" in the "[data-region='catquizlab-flow-head']" "css_element"
    And "[data-region='catquizlab-flow-head'] [data-reason='max_items_reached']" "css_element" should exist
    And I should see "Final SE" in the "[data-region='catquizlab-flow-head']" "css_element"
    And I should see "Final test information" in the "[data-region='catquizlab-flow-head']" "css_element"
    # The steps: standard error and information per step, and what the engine does not record, marked.
    And I should see "SE after this step" in the "[data-region='catquizlab-flow-steps']" "css_element"
    And I should see "TI@n (global)" in the "[data-region='catquizlab-flow-steps']" "css_element"
    And I should see "N/A" in the "[data-region='catquizlab-flow-steps']" "css_element"
    And I should see "on the last step it agrees with the information the CAT engine reports"

  Scenario: Twins of one simulated person are compared in one plot, and the simulated people are shown
    Given the following "local_catquizlab > experiment" exists:
      | name            | Behat twins      |
      | sweepstrategies | fastest,allsubs  |
    And the experiment "Behat twins" has been expanded into runs
    And the runs of "Behat twins" share twin "r001-t00007" with full traces
    When I open the test flow of "Behat twins"
    Then I should see "Compare related tests"
    # The twin family is chosen by default, and both of its tests are drawn, one colour per strategy.
    And the field "Twin family" matches value "r001-t00007 (2 tests)"
    And "[data-region='catquizlab-comparison'] polyline[data-region='series']" "css_element" should exist
    And I should see "CAT (fastest)" in the "[data-region='catquizlab-comparison']" "css_element"
    And I should see "Infer all subscales (allsubs)" in the "[data-region='catquizlab-comparison']" "css_element"
    And "[data-download='svg']" "css_element" should exist
    And "[data-download='csv']" "css_element" should exist
    # Another metric.
    When I set the field "Metric" to "SE after this step"
    And I press "Compare"
    Then "[data-region='catquizlab-comparison'] polyline[data-region='series']" "css_element" should exist
    # The simulated people, each twin family once.
    When I follow "Overview"
    Then I should see "Simulated people"
    And I should see "1 simulated people in this selection" in the "[data-region='catquizlab-people-stats']" "css_element"
