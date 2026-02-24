<?php

namespace App\Form;

use App\Entity\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TargetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('type', TextType::class, ['required' => false])
            ->add('constellation', TextType::class, ['required' => false])
            ->add('ra', NumberType::class, ['required' => false, 'scale' => 6])
            ->add('dec', NumberType::class, ['required' => false, 'scale' => 6])
            ->add('visualMag', NumberType::class, ['required' => false, 'scale' => 2])
            ->add('telescopiusUrl', TextType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
            ->add('previewImage', FileType::class, [
                'required' => false,
                'mapped'   => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Target::class,
        ]);
    }
}
