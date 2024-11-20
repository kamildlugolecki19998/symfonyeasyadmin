<?php

namespace App\EasyAdmin;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;


class VotesField implements FieldInterface
{
    use FieldTrait;
    public static function new(string $propertyName, ?string $label = null) :self
    {
        return (new self())
        ->setProperty($propertyName)
        ->setLabel($label)
        ->setTemplatePath('admin/field/votes.html.twig')
        ->setFormType(IntegerType::class)
        ->addCssClass('field-integer')
        ->setDefaultColumns('col-md-4 col-xxl-3');
    }

}
