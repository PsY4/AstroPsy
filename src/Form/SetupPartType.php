<?php

namespace App\Form;

use App\Entity\Setup;
use App\Entity\SetupPart;
use http\Url;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Contracts\Translation\TranslatorInterface;

class SetupPartType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'form.setup_part.type.label',
                'row_attr' => [
                    'class' => "col-md-4",
                ],
                'choices' => [
                    'form.setup_part.type.main_scope' => 'main-scope',
                    'form.setup_part.type.main_cam' => 'main-cam',
                    'form.setup_part.type.mount' => 'mount',
                    'form.setup_part.type.filter' => 'filter',
                    'form.setup_part.type.accessory' => 'accessory',
                    'form.setup_part.type.software' => 'software',
                    'form.setup_part.type.guiding_scope' => 'guiding-scope',
                    'form.setup_part.type.guiding_cam' => 'guiding-cam',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => [
                    'class' => "form-control form-control-lg",
                ]
            ])
            ->add('make', TextType::class, [
                'label' => 'form.setup_part.make.label',
                'row_attr' => [
                    'class' => "col-md-4",
                ],
                'attr' => [
                    'class' => "form-control form-control-lg",
                    'placeholder' => $this->translator->trans('form.setup_part.make.placeholder'),
                ]
            ])
            ->add('model', TextType::class, [
                'label' => 'form.setup_part.model.label',
                'row_attr' => [
                    'class' => "col-md-4",
                ],
                'attr' => [
                    'class' => "form-control form-control-lg",
                    'placeholder' => $this->translator->trans('form.setup_part.model.placeholder'),
                ]
            ])
            ->add('url', UrlType::class, [
                'label'            => 'form.setup_part.url.label',
                'required'         => false,
                'default_protocol' => null,
                'attr'             => [
                    'class'       => "form-control",
                    'placeholder' => $this->translator->trans('form.setup_part.url.placeholder'),
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'form.setup_part.notes.label',
                'row_attr' => [
                    'class' => "col-md-12",
                ],
                'required' => false,
                'attr' => [
                    'class' => "form-control",
                ]
            ])
            ->add('images', FileType::class, [
                'label' => 'form.setup_part.images.label',
                'multiple' => true,
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => "form-control",
                ],
                'constraints' => [
                    new All([
                        new File([
                            'maxSize' => '5M',
                            'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                            'mimeTypesMessage' => $this->translator->trans('form.setup_part.images.error'),
                        ])
                    ]),
                ],
            ])
            ->add('deleteImages', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SetupPart::class,
        ]);
    }
}
