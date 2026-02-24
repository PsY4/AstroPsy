<?php

namespace App\Form;

use App\Entity\Author;
use App\Entity\Observatory;
use App\Entity\Session;
use App\Entity\Setup;
use App\Entity\Target;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SessionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('observatory', EntityType::class, [
                'class' => Observatory::class,
                'choice_label' => 'name',
            ])
            ->add('authors', EntityType::class, [
                'class' => Author::class,
                'choice_label' => 'name',
                'multiple' => true,
            ])
            ->add('setup', EntityType::class, [
                'class'        => Setup::class,
                'choice_label' => 'name',
                'required'     => false,
            ])
            ->add('astrobin')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Session::class,
        ]);
    }
}
