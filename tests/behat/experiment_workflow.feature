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
    Then I should see "Detect weakest subscale"

  Scenario: Runs can be filtered and opened
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat runs |
      | replications | 2          |
    And the experiment "Behat runs" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "All runs"
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
    Then I should see "already has runs"

  Scenario: The results page offers the filter bar and the tabs
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat results |
    And the experiment "Behat results" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Results and evaluation"
    Then I should see "Results and evaluation"
    And I should see "Overview"
    And I should see "Global metrics"
    And I should see "Robustness"
    And I should see "Test flow"
    And I should see "Any strategy"
    And I should see "No attempts match this filter yet."

  Scenario: The local diagnostics tabs are reachable and name their subject
    Given the following "local_catquizlab > experiment" exists:
      | name     | Behat local |
      | strategy | lowestsub   |
    And the experiment "Behat local" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "Results and evaluation"
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
    And I follow "Results and evaluation"
    When I follow "Robustness"
    Then I should see "Robustness against pool disturbances"
    And I should see "measured against the ideal pool"

  Scenario: The test-flow tab explains the feasibility arithmetic
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat flow |
    And the experiment "Behat flow" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "Results and evaluation"
    When I follow "Test flow"
    Then I should see "Test flow and feasibility"
    And I should see "I = 1 / SE"

  Scenario: Raw data and export name their levels and their provenance
    Given the following "local_catquizlab > experiment" exists:
      | name | Behat export |
    And the experiment "Behat export" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    And I follow "Results and evaluation"
    When I follow "Raw data"
    Then I should see "Raw data"
    And I should see "Data level"
    When I follow "Export"
    Then I should see "Export the current selection"
    And I should see "Attempt level"
    And I should see "Item level"
    And I should see "What the file will say about itself"

  Scenario: The landing page says when no experiment course is configured
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "No experiment course is configured"
    And I should see "Choose an experiment course"

  Scenario: A configured experiment course is shown and linked
    Given the following "courses" exist:
      | fullname        | shortname |
      | CATLab Studies  | catlab    |
    And the course "catlab" is the experiment course
    When I navigate to "Reports > CAT experiment suite" in site administration
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
