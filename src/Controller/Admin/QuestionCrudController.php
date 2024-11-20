<?php

namespace App\Controller\Admin;

use App\EasyAdmin\VotesField;
use App\Entity\Question;
use App\Entity\Topic;
use App\Entity\User;
use App\Service\CsvExporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

#[IsGranted('ROLE_MODERATOR')]
class QuestionCrudController extends AbstractCrudController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator, private readonly RequestStack $requestStack)
    {
    }
    public static function getEntityFqcn(): string
    {
        return Question::class;
    }


    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();


        yield FormField::addTab('Basic Data');
        yield FormField::addFieldset('Field set')->collapsible();
        yield Field::new('name')
            ->setSortable(false)
            ->setColumns(5);
        yield FormField::addColumn(5);
        yield Field::new('slug')
            ->hideOnIndex()
            ->setFormTypeOption(
                'disabled',
                $pageName !== Crud::PAGE_NEW
            )
            ->setColumns(5);
            yield FormField::addColumn(10);
        yield TextareaField::new('question')
            ->hideOnIndex()
            ->setFormTypeOptions([
                'row_attr' => [
                    'data-controller' => 'snarkdown'
                ],
                'attr' => [
                    'data-snakdown-target' => 'input',
                    'data-action' => 'snarkdown#render'
                ]
            ])
            ->setHelp('Preview');
        yield AssociationField::new('topic');

        yield VotesField::new('Votes', 'Total votes')
            ->setTextAlign('right')
            ->setPermission("ROLE_SUPER_ADMIN");

        yield FormField::addTab('Additional data')
            ->setHelp('Extra Additional data')
            ->setIcon('info');
        yield AssociationField::new('askedBy')
            ->autocomplete()
            ->formatValue(
                function ($value, Question $question) {
                    if (!$user = $question->getAskedBy()) {
                        return null;
                    }

                    return sprintf('%s&nbsp;(%s)', $user->getEmail(), $user->getQuestions()->count());
                }
            )
            ->setQueryBuilder(function (QueryBuilder $queryBuilder) {
                $queryBuilder->andWhere('entity.enabled = :enabled')
                    ->setParameter('enabled', true);
            });
        yield Field::new('createdAt')
            ->hideOnForm();

        yield AssociationField::new('answers')
            ->autocomplete() // for getting data with ajax (asynchronously)
            ->setFormTypeOption('by_reference', false);

        yield AssociationField::new('updatedBy')
            ->onlyOnDetail();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(
                [
                    'askedBy.enabled' => 'DESC',
                    'createdAt' => 'DESC'
                ]
            )
            ->setPageTitle(Crud::PAGE_INDEX, 'All Questions');
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewAction = Action::new('view', 'View on site', 'fa fa-eye')
            ->linkToUrl(function (Question $question) {
                return $this->generateUrl('app_question_show', [
                    'slug' => $question->getSlug()
                ]);
            })
            ->addCssClass('btn btn-success');


        $approveActtion = Action::new('approve', 'Approve', 'fa fa-check-circle')
            ->addCssClass('btn btn-success')
            ->displayAsButton()
            ->linkToCrudAction('approve')
            ->setTemplatePath('/admin/approve_action.html.twig')
            ->displayIf(function (Question $question): bool {
                return !$question->getIsApproved();
            });


        $exportAction = Action::new('export', 'Export', 'fa fa-download')
            ->addCssClass('btn btn-success')
            ->linkToUrl(function () {
                $request = $this->requestStack->getCurrentRequest();

                return $this->adminUrlGenerator->setAll($request->query->all())
                    ->setAction('export')
                    ->generateUrl();

            })
            ->createAsGlobalAction();


        return parent::configureActions($actions)
            //    ->update(Crud::PAGE_INDEX, Action::DELETE, function(Action $action){
            //         $action->displayIf(function (Question $question){
            //             return !$question->getIsApproved();
            //         });

            //         return $action;
            //    })
            ->setPermission(Action::EDIT, 'ROLE_MODERATOR')
            ->setPermission(Action::INDEX, 'ROLE_MODERATOR')
            ->setPermission(Action::DETAIL, 'ROLE_MODERATOR')
            ->setPermission(Action::NEW , 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::BATCH_DELETE, 'ROLE_SUPER_ADMIN')
            ->add(Crud::PAGE_DETAIL, $viewAction)
            ->add(Crud::PAGE_INDEX, $viewAction)
            ->add(Crud::PAGE_DETAIL, $approveActtion)
            ->add(Crud::PAGE_INDEX, $exportAction)
            ->reorder(
                Crud::PAGE_DETAIL,
                [$approveActtion->__toString(), $viewAction->__toString(), Action::EDIT, Action::INDEX, Action::DELETE]
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return parent::configureFilters($filters)
            ->add('topic')
            ->add('createdAt')
            ->add('votes')
            ->add('name');
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new \LogicException('There is not proper user');
        }

        $entityInstance->setUpdatedBy($this->getUser());

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance->getIsApproved()) {
            throw new \Exception('Removing approved enetity is forbiden');
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function approve(
        AdminContext $adminContext,
        EntityManagerInterface $entityManagerInterface,
        AdminUrlGenerator $adminUrlGenerator
    ): RedirectResponse {
        $question = $adminContext->getEntity()->getInstance();

        if (!$question instanceof Question) {
            throw new \LogicException('Entity not found or there is not a Question enity');
        }

        $question->setIsApproved(true);
        $entityManagerInterface->flush();

        $targetUrl = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Crud::PAGE_DETAIL)
            ->setEntityId($question->getId())
            ->generateUrl();

        return $this->redirect($targetUrl);
    }

    public function export(AdminContext $adminContext, CsvExporter $csvExporter): Response
    {
        $fields       = FieldCollection::new($this->configureFields(Crud::PAGE_INDEX));
        $filters      = $this->container->get(FilterFactory::class)->create($adminContext->getCrud()->getFiltersConfig(), $fields, $adminContext->getEntity());
        $queryBuilder = $this->createIndexQueryBuilder($adminContext->getSearch(), $adminContext->getEntity(), $fields, $filters);

        return $csvExporter->createResponseFromQueryBuilder($queryBuilder, $fields, 'export.csv');
    }
}
