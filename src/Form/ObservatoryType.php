<?php

namespace App\Form;

use App\Entity\Observatory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ObservatoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('lat')
            ->add('lon')
            ->add('altitudeHorizon', NumberType::class, [
                'required' => false,
                'label'    => 'observatory.altitude_horizon',
                'attr'     => ['min' => 0, 'max' => 45, 'step' => 1],
                'html5'    => true,
            ])
            ->add('live', UrlType::class, [
                'required'         => false,
                'label'            => 'observatory.live',
                'default_protocol' => null,
            ])
            ->add('comments')
            ->add('city')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Observatory::class,
        ]);
    }
}
