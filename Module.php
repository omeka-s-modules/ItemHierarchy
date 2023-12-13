<?php
namespace ItemHierarchy;

use Omeka\Module\AbstractModule;
use Omeka\Entity\Item;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Fieldset;
use Laminas\Mvc\MvcEvent;
use Laminas\EventManager\Event;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec('CREATE TABLE item_hierarchy_grouping (id INT AUTO_INCREMENT NOT NULL, item_set_id INT DEFAULT NULL, hierarchy_id INT NOT NULL, parent_grouping INT DEFAULT NULL, `label` VARCHAR(255) DEFAULT NULL, INDEX IDX_888D30B9960278D7 (item_set_id), INDEX IDX_888D30B9582A8328 (hierarchy_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $connection->exec('CREATE TABLE item_hierarchy (id INT AUTO_INCREMENT NOT NULL, `label` VARCHAR(255) NOT NULL, position INT NOT NULL, UNIQUE INDEX UNIQ_F6A03E5EEA750E8 (`label`), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $connection->exec('ALTER TABLE item_hierarchy_grouping ADD CONSTRAINT FK_888D30B9960278D7 FOREIGN KEY (item_set_id) REFERENCES item_set (id) ON DELETE CASCADE;');
        $connection->exec('ALTER TABLE item_hierarchy_grouping ADD CONSTRAINT FK_888D30B9582A8328 FOREIGN KEY (hierarchy_id) REFERENCES item_hierarchy (id) ON DELETE CASCADE;');
    }
    
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec('ALTER TABLE item_hierarchy_grouping DROP FOREIGN KEY FK_888D30B9960278D7');
        $connection->exec('ALTER TABLE item_hierarchy_grouping DROP FOREIGN KEY FK_888D30B9582A8328');
        $connection->exec('DROP TABLE item_hierarchy');
        $connection->exec('DROP TABLE item_hierarchy_grouping');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.sidebar',
            [$this, 'addItemHierarchies']
        );
    }

    // Add relevant hierarchy breadcrumbs to item display sidebar
    public function addItemHierarchies(Event $event)
    {
        $view = $event->getTarget();
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        if ($view->item->itemSets()) {
            echo '<div class="meta-group">';
            echo '<h4>' . $view->translate('Hierarchies') . '</h4>';
            foreach ($view->item->itemSets() as $currentItemSet) {
                $groupings = $api->search('item_hierarchy_grouping', ['item_set' => $currentItemSet->id()])->getContent();
                $this->buildBreadcrumb($groupings, $currentItemSet);
            }
            echo '</div>';
        }
    }

    protected function buildBreadcrumb(array $groupings, $currentItemSet)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        static $printedGroupings = [];
        $iterate = function ($groupings) use ($api, $currentItemSet, &$iterate, &$allGroupings, &$printedGroupings, &$currentHierarchy) {
            foreach ($groupings as $key => $grouping) {
                // Continue if grouping has already been printed
                if (isset($printedGroupings) && in_array($grouping, $printedGroupings)) {
                    continue;
                }

                if ($currentHierarchy != $grouping->getHierarchy()) {
                    echo '<div class="value">';
                    $currentHierarchy = $grouping->getHierarchy();
                    $allGroupings = $api->search('item_hierarchy_grouping', ['hierarchy' => $currentHierarchy])->getContent();
                }

                if ($grouping->getParentGrouping() != 0) {
                    // $iterate through any groupings with current grouping as child
                    $parentArray = array_filter($allGroupings, function($parent) use($grouping) {
                        return $parent->id() == $grouping->getParentGrouping();
                    });
                    if (count($parentArray) > 0) {
                        $iterate($parentArray, $currentItemSet);
                        break;
                    }
                }

                try {
                    $itemSet = $api->read('item_sets', $grouping->getItemSet())->getContent();
                } catch (\Exception $e) {
                    // Print groupings without assigned itemSet
                    $itemSet = null;
                    echo $grouping->getLabel();
                }

                if (!is_null($itemSet)) {
                    // Bold groupings with current itemSet assigned
                    if ($grouping->getItemSet()->getId() == $currentItemSet->id()) {
                        echo "<b>" . $itemSet->link($grouping->getLabel()) . "</b>";
                    } else {
                        echo $itemSet->link($grouping->getLabel());
                    }
                }

                // Return any groupings with current grouping as parent
                $childArray = array_filter($allGroupings, function($child) use($grouping) {
                    return $child->getParentGrouping() == $grouping->id();
                });

                // Remove already printed groupings from $allGroupings array
                $allGroupings = array_filter($allGroupings, function($child) use($grouping) {
                    return $child->id() != $grouping->id();
                });

                $printedGroupings[] = $grouping;

                if (count($childArray) > 0) {
                    echo " => ";
                    $iterate($childArray, $currentItemSet);
                    continue;
                }
                echo '</div>';
            }
        };
        $iterate($groupings, $currentItemSet);
    }
}
