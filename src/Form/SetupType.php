<?php

namespace App\Form;

use App\Entity\Author;
use App\Entity\Observatory;
use App\Entity\Setup;
use App\Form\Type\MeetlyOptionRuleType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class SetupType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.setup.name.label',
                'attr' => [
                    'class' => "form-control form-control-lg",
                    'placeholder' => $this->translator->trans('form.setup.name.placeholder'),
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'form.setup.notes.label',
                'required' => false,
                'attr' => [
                    'class' => "form-control",
                    'placeholder' => ''
                ]
            ])
            ->add('uploadLogo', FileType::class, [
                'label' => 'form.setup.logo.label',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'class' => "form-control form-control-lg",
                ]
            ])
            ->add('author', EntityType::class, [
                'class' => Author::class,
                'choice_label' => 'name',
                'row_attr' => [
                    'class' => "col-md-6",
                ],
                'attr' => [
                    'class' => "col-md-6 form-control",
                    'placeholder' => ''
                ]
            ])
            ->add('observatory', EntityType::class, [
                'class' => Observatory::class,
                'choice_label' => 'name',
                'row_attr' => [
                    'class' => "col-md-6",
                ],
                'attr' => [
                    'class' => "form-control",
                    'placeholder' => ''
                ]
            ])
            ->add('sensorWPx', NumberType::class, [
                'label'    => 'form.setup.sensor_w_px.label',
                'required' => false,
                'scale'    => 0,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 9576'],
            ])
            ->add('sensorHPx', NumberType::class, [
                'label'    => 'form.setup.sensor_h_px.label',
                'required' => false,
                'scale'    => 0,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 6388'],
            ])
            ->add('pixelSizeUm', NumberType::class, [
                'label'    => 'form.setup.pixel_size_um.label',
                'required' => false,
                'scale'    => 3,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 3.760'],
            ])
            ->add('focalMm', NumberType::class, [
                'label'    => 'form.setup.focal_mm.label',
                'required' => false,
                'scale'    => 1,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 800'],
            ])
            ->add('slewTimeMin', IntegerType::class, [
                'label'    => 'form.setup.slew_time_min.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 5'],
            ])
            ->add('autofocusTimeMin', IntegerType::class, [
                'label'    => 'form.setup.autofocus_time_min.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 10'],
            ])
            ->add('autofocusIntervalMin', IntegerType::class, [
                'label'    => 'form.setup.autofocus_interval_min.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 60'],
            ])
            ->add('meridianFlipTimeMin', IntegerType::class, [
                'label'    => 'form.setup.meridian_flip_time_min.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 5'],
            ])
            ->add('minShootTimeMin', IntegerType::class, [
                'label'    => 'form.setup.min_shoot_time_min.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 30'],
            ])
            ->add('imagingType', ChoiceType::class, [
                'label'   => 'form.setup.imaging_type.label',
                'choices' => ['MONO' => 'MONO', 'OSC' => 'OSC'],
                'attr'    => ['class' => 'form-control'],
            ])
            ->add('cameraCoolingTemp', NumberType::class, [
                'label'    => 'form.setup.camera_cooling_temp.label',
                'required' => false,
                'scale'    => 1,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: -10'],
            ])
            ->add('cameraGain', IntegerType::class, [
                'label'    => 'form.setup.camera_gain.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => '-1 = auto'],
            ])
            ->add('cameraOffset', IntegerType::class, [
                'label'    => 'form.setup.camera_offset.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => '-1 = auto'],
            ])
            ->add('cameraBinning', IntegerType::class, [
                'label'    => 'form.setup.camera_binning.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 1'],
            ])
            ->add('ditherEvery', IntegerType::class, [
                'label'    => 'form.setup.dither_every.label',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'ex: 3'],
            ])
            ->add('filtersConfigRaw', HiddenType::class, [
                'mapped'   => false,
                'required' => false,
            ])
            ->add('setupParts', CollectionType::class, [
                'label' => 'form.setup.parts.label',
                'entry_type' => SetupPartType::class,
                'allow_add'     => true,
                'allow_delete'  => true,
                'prototype'     => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Setup::class,
        ]);
    }
}
